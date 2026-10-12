<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use App\Core\Automation\AutomationRegistry;
use App\Core\Billing\UsageMeter;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use App\Modules\Automation\Engine\Outbound\OutboundWebhook;
use App\Modules\Automation\Enums\ApprovalStatus;
use App\Modules\Automation\Enums\NodeType;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Events\RunFailed;
use App\Modules\Automation\Events\RunFinished;
use App\Modules\Automation\Graph\Graph;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Limits;
use App\Modules\Automation\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Executes a run one persisted step at a time.
 *
 * Model: a run is a set of step rows (tokens on the graph). `advance()` takes the run's lease, applies any
 * human decisions and due waits, executes at most one ready step, commits that step together with its
 * successors, and reports whether more work is ready. All state lives in the database, so a lost job, a
 * deploy or a crashed worker only delays a run: the sweeper re-dispatches it and the interrupted step is
 * retried under the same idempotency key.
 *
 * Authorization is the member the workflow runs as. Before every step the actor's live permissions and the
 * workspace's live entitlements are re-checked ({@see ActorGuard}); a denial fails the run without running
 * the step and without routing to an error handler.
 */
final class RunExecutor
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EntitlementChecker $entitlements,
        private readonly ActorGuard $guard,
        private readonly AutomationRegistry $registry,
        private readonly ConditionEvaluator $conditions,
        private readonly VariableResolver $variables,
        private readonly Transformer $transformer,
        private readonly SchemaChecker $schemas,
        private readonly Redactor $redactor,
        private readonly OutboundWebhook $webhooks,
        private readonly RunScope $scope,
        private readonly UsageMeter $meter,
        private readonly ApprovalManager $approvals,
    ) {}

    /**
     * Execute at most one step of a run.
     *
     * @return bool Whether more work is ready to run immediately.
     */
    public function advance(string $runId): bool
    {
        $run = $this->claim($runId);

        if ($run === null) {
            return false;
        }

        try {
            return $this->work($run);
        } catch (Throwable $exception) {
            report($exception);

            $this->finish($run, RunStatus::Failed, 'engine_error', 'The workflow engine hit an unexpected error.');

            return false;
        } finally {
            Run::query()->whereKey($run->id)->update(['locked_until' => null]);
        }
    }

    /** Cancel a run now. Only called when no worker holds its lease. */
    public function cancel(Run $run, string $code = 'cancelled', string $message = ''): void
    {
        $this->finish($run, RunStatus::Cancelled, $code, $message);
    }

    // ------------------------------------------------------------------ claiming and the main loop

    private function claim(string $runId): ?Run
    {
        return DB::transaction(function () use ($runId): ?Run {
            $run = Run::query()->lockForUpdate()->find($runId);

            if ($run === null || $run->status->isTerminal()) {
                return null;
            }

            if ($run->locked_until !== null && $run->locked_until->isFuture()) {
                return null;
            }

            $busy = Run::query()
                ->where('status', RunStatus::Running->value)
                ->where('locked_until', '>', Carbon::now())
                ->whereKeyNot($run->id)
                ->count();

            if ($busy >= Limits::int('concurrent_runs')) {
                // Stays queued; the sweeper hands it out again once capacity frees up.
                return null;
            }

            $run->forceFill(['locked_until' => Carbon::now()->addSeconds(Limits::int('run_lease_seconds'))])->save();

            return $run;
        });
    }

    private function work(Run $run): bool
    {
        $workflow = Workflow::query()->find($run->workflow_id);

        if ($workflow === null) {
            $this->finish($run, RunStatus::Failed, 'workflow_missing', 'The workflow no longer exists.');

            return false;
        }

        if ($run->cancel_requested) {
            $this->finish($run, RunStatus::Cancelled, 'cancelled', 'Cancelled.');

            return false;
        }

        if ($run->started_at !== null && $run->started_at->lt(Carbon::now()->subDays(Limits::int('max_run_age_days')))) {
            $this->finish($run, RunStatus::Failed, 'run_timeout', 'The run exceeded its maximum age.');

            return false;
        }

        if (! $run->isDryRun() && $workflow->status->value === 'archived') {
            $this->finish($run, RunStatus::Cancelled, 'workflow_archived', 'The workflow was archived.');

            return false;
        }

        if (! $this->entitlements->allows($this->tenants->require(), 'automation')) {
            $this->finish($run, RunStatus::Failed, 'automation_not_entitled', 'Workflow automation is not available to this workspace.');

            return false;
        }

        $graph = $this->graphOf($run);
        $context = is_array($run->context) ? $run->context : [];

        // Anything left "running" belongs to a worker that died holding the lease.
        RunStep::query()->where('run_id', $run->id)->where('status', StepStatus::Running->value)
            ->update(['status' => StepStatus::Pending->value]);

        $decided = $this->applyApprovals($run, $workflow, $graph, $context);
        $woken = ! $run->status->isTerminal() && $this->wakeWaiting($run, $workflow, $graph, $context);

        if (($decided || $woken) && ! $run->status->isTerminal()) {
            $this->persistContext($run, $context);
        }

        if ($run->status->isTerminal()) {
            return false;
        }

        $step = RunStep::query()
            ->where('run_id', $run->id)
            ->where('status', StepStatus::Pending->value)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', Carbon::now()))
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();

        if ($step === null) {
            return $this->settle($run, $workflow, $context);
        }

        if ($run->steps_executed >= Limits::int('max_steps_per_run')) {
            $this->finish($run, RunStatus::Failed, 'step_limit', 'The run reached the maximum number of steps.');

            return false;
        }

        $this->execute($run, $workflow, $graph, $step, $context);

        return ! $run->status->isTerminal();
    }

    // ------------------------------------------------------------------ one step

    /**
     * @param  array<string, mixed>  $context
     */
    private function execute(Run $run, Workflow $workflow, Graph $graph, RunStep $step, array &$context): void
    {
        $node = $graph->node($step->node_id);

        $run->forceFill(['status' => RunStatus::Running, 'started_at' => $run->started_at ?? Carbon::now()])->save();

        if ($node === null) {
            $this->apply($run, $workflow, $graph, $step, StepOutcome::fail('node_missing', 'The node no longer exists in this version.', fatal: true), $context);

            return;
        }

        $step->forceFill([
            'status' => StepStatus::Running,
            'attempt' => $step->attempt + 1,
            'started_at' => $step->started_at ?? Carbon::now(),
        ])->save();

        if (! $run->isDryRun() && $run->steps_executed === 0) {
            $this->meter->record('automation.runs', 1, 'automation:run:'.$run->id, ['workflow' => $workflow->id]);
        }

        $started = microtime(true);
        $resolution = [...$context, 'run' => [
            'id' => $run->id, 'mode' => $run->mode->value, 'source' => $run->source->value,
            'started_at' => $run->started_at?->toIso8601String(),
        ], 'workflow' => ['id' => $workflow->id, 'name' => $workflow->name]];

        try {
            $denial = $run->isDryRun() ? null : $this->denial($workflow, $node);

            $outcome = $denial !== null
                ? StepOutcome::fail($denial, 'This step may no longer run: '.str_replace('_', ' ', $denial).'.', fatal: true)
                : $this->handle($run, $workflow, $graph, $step, $node, $context, $resolution);
        } catch (Throwable $exception) {
            $outcome = $this->failureOf($exception);
        }

        $this->apply($run, $workflow, $graph, $step, $outcome, $context, (int) round((microtime(true) - $started) * 1000));
    }

    /**
     * The denial code for a node, or null. Runs for live runs only (a test run performs nothing).
     *
     * @param  array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}  $node
     */
    private function denial(Workflow $workflow, array $node): ?string
    {
        $actor = $workflow->actor_membership_id === null ? null : $workflow->actor()->with('user')->first();

        // A live workflow always needs its actor to still be allowed to publish workflows.
        $baseline = $this->guard->denial($actor, Permissions::WORKFLOWS_PUBLISH, 'automation');

        if ($baseline !== null) {
            return $baseline;
        }

        $config = $node['config'];

        if ($node['type'] === NodeType::Trigger) {
            $class = $this->registry->registeredTriggers()[(string) ($config['trigger_key'] ?? '')] ?? null;

            return $class === null ? 'unknown_trigger' : $this->guard->denial($actor, $class::requiredPermission(), $class::module());
        }

        if ($node['type'] === NodeType::Action) {
            $class = $this->registry->registeredActions()[(string) ($config['action_key'] ?? '')] ?? null;

            return $class === null ? 'unknown_action' : $this->guard->denial($actor, $class::requiredPermission(), $class::module());
        }

        return null;
    }

    /**
     * @param  array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}  $node
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $resolution
     */
    private function handle(Run $run, Workflow $workflow, Graph $graph, RunStep $step, array $node, array &$context, array $resolution): StepOutcome
    {
        $config = $node['config'];
        $dry = $run->isDryRun();

        return match ($node['type']) {
            NodeType::Trigger => StepOutcome::proceed(is_array($context['trigger'] ?? null) ? $context['trigger'] : []),
            NodeType::Condition => $this->condition($config, $resolution),
            NodeType::Branch => $this->branch($config, $resolution),
            NodeType::Action => $this->action($run, $step, $config, $resolution),
            NodeType::Delay => $this->delay($config, $dry),
            NodeType::Schedule => $this->scheduleNode($config, $resolution, $dry),
            NodeType::Parallel => StepOutcome::proceed(['branches' => count($graph->outgoing($node['id']))]),
            NodeType::Approval => $this->approval($run, $step, $workflow, $config, $resolution, $dry),
            NodeType::Webhook => $this->webhook($run, $step, $config, $resolution, $dry),
            NodeType::Transform => $this->transform($config, $resolution, $context),
            NodeType::Loop => $this->loop($node['id'], $config, $resolution, $context),
            NodeType::ErrorHandler => $this->errorHandler($config, $context),
            NodeType::End => ($config['status'] ?? 'success') === 'failed'
                ? StepOutcome::end('failed', (string) ($config['message'] ?? 'The workflow ended in failure.'))
                : StepOutcome::end('success'),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     */
    private function condition(array $config, array $resolution): StepOutcome
    {
        $result = $this->conditions->passes($config, $resolution);

        return StepOutcome::proceed(['result' => $result], [$result ? 'true' : 'false']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     */
    private function branch(array $config, array $resolution): StepOutcome
    {
        foreach (is_array($config['cases'] ?? null) ? $config['cases'] : [] as $case) {
            if (is_array($case) && $this->conditions->passes($case, $resolution)) {
                return StepOutcome::proceed(['case' => $case['id'] ?? null], [(string) $case['id']]);
            }
        }

        return StepOutcome::proceed(['case' => 'default'], ['default']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     */
    private function action(Run $run, RunStep $step, array $config, array $resolution): StepOutcome
    {
        $key = (string) ($config['action_key'] ?? '');
        $class = $this->registry->registeredActions()[$key] ?? null;

        if ($class === null) {
            return StepOutcome::fail('unknown_action', 'This action is not available.', fatal: true);
        }

        $input = $this->variables->resolve(is_array($config['input'] ?? null) ? $config['input'] : [], $resolution);
        $input = is_array($input) ? $input : [];
        $problems = $this->schemas->check($class::inputSchema(), $input);

        if ($problems !== []) {
            return StepOutcome::fail('invalid_input', 'The action input is invalid: '.implode('; ', array_slice($problems, 0, 3)).'.', input: $input);
        }

        if ($run->isDryRun()) {
            return StepOutcome::proceed(['dry_run' => true, 'action' => $key], [null], $input);
        }

        // Stable per step: a retry of the same step reuses the action's durable receipt.
        $idempotencyKey = 'wf:'.$run->id.':'.$step->id;

        $output = $this->scope->within($run->id, $run->depth, function () use ($class, $input, $idempotencyKey): array {
            return app($class)->execute($input, $idempotencyKey);
        });

        return StepOutcome::proceed($output, [null], $input);
    }

    /** @param  array<string, mixed>  $config */
    private function delay(array $config, bool $dry): StepOutcome
    {
        $minutes = (int) ($config['amount'] ?? 0) * match ($config['unit'] ?? '') {
            'hours' => 60, 'days' => 1440, default => 1,
        };

        if ($dry) {
            return StepOutcome::proceed(['would_wait_minutes' => $minutes, 'skipped' => true]);
        }

        return StepOutcome::wait(CarbonImmutable::now()->addMinutes(max(1, $minutes)), ['wait_minutes' => $minutes]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     */
    private function scheduleNode(array $config, array $resolution, bool $dry): StepOutcome
    {
        $timezone = $this->tenants->require()->timezone ?: 'UTC';
        $now = CarbonImmutable::now();

        if (($config['mode'] ?? 'cron') === 'at') {
            $at = $this->variables->resolve($config['at'] ?? null, $resolution);

            try {
                $until = is_string($at) ? CarbonImmutable::parse($at, $timezone)->utc() : null;
            } catch (Throwable) {
                $until = null;
            }

            if ($until === null) {
                return StepOutcome::fail('bad_schedule', 'The date to wait for is not valid.');
            }
        } else {
            $until = CronSchedule::next((string) ($config['cron'] ?? ''), $timezone, $now);

            if ($until === null) {
                return StepOutcome::fail('bad_schedule', 'The schedule is not valid.');
            }
        }

        $maxDays = Limits::int('max_delay_days');

        if ($until->gt($now->addDays($maxDays))) {
            return StepOutcome::fail('wait_too_long', "A workflow cannot wait more than {$maxDays} days.");
        }

        if ($dry || $until->lte($now)) {
            return StepOutcome::proceed(['resume_at' => $until->toIso8601String(), 'skipped' => true]);
        }

        return StepOutcome::wait($until, ['resume_at' => $until->toIso8601String()]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     */
    private function approval(Run $run, RunStep $step, Workflow $workflow, array $config, array $resolution, bool $dry): StepOutcome
    {
        if ($dry) {
            return StepOutcome::proceed(['decision' => 'approved', 'simulated' => true], ['approved']);
        }

        $config['title'] = $this->variables->resolve($config['title'] ?? '', $resolution);
        $config['message'] = $this->variables->resolve($config['message'] ?? null, $resolution);

        $existing = Approval::query()->where('run_step_id', $step->id)->first();
        $approval = $existing ?? $this->approvals->request($run, $step, $workflow, $config);

        return StepOutcome::awaitApproval(
            CarbonImmutable::instance($approval->expires_at ?? Carbon::now()->addHours(Limits::int('approval_default_hours'))),
            ['approval_id' => $approval->id],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     */
    private function webhook(Run $run, RunStep $step, array $config, array $resolution, bool $dry): StepOutcome
    {
        $endpoint = WebhookEndpoint::query()->find((string) ($config['endpoint_id'] ?? ''));

        if ($endpoint === null || ! $endpoint->is_active) {
            return StepOutcome::fail('endpoint_unavailable', 'The webhook endpoint does not exist or is switched off.');
        }

        $payload = $this->variables->resolve(is_array($config['payload'] ?? null) ? $config['payload'] : [], $resolution);
        $payload = is_array($payload) ? $payload : [];

        if ($dry) {
            return StepOutcome::proceed(['dry_run' => true, 'endpoint' => $endpoint->name], [null], $payload);
        }

        $result = $this->webhooks->send($endpoint, $payload, 'wf:'.$run->id.':'.$step->id);
        $endpoint->forceFill(['last_called_at' => Carbon::now(), 'last_status_code' => $result['status'] ?: null])->save();

        if ($result['message'] !== '') {
            return StepOutcome::fail('webhook_failed', $result['message'], retryable: $result['retryable'], input: $payload);
        }

        return StepOutcome::proceed(['status' => $result['status']], [null], $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     * @param  array<string, mixed>  $context
     */
    private function transform(array $config, array $resolution, array &$context): StepOutcome
    {
        try {
            $output = $this->transformer->apply(
                array_values(array_filter(is_array($config['mappings'] ?? null) ? $config['mappings'] : [], 'is_array')),
                $resolution,
            );
        } catch (InvalidArgumentException $exception) {
            return StepOutcome::fail('transform_failed', $exception->getMessage());
        }

        $context['vars'] = [...(is_array($context['vars'] ?? null) ? $context['vars'] : []), ...$output];

        return StepOutcome::proceed($output);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resolution
     * @param  array<string, mixed>  $context
     */
    private function loop(string $nodeId, array $config, array $resolution, array &$context): StepOutcome
    {
        $loops = is_array($context['loops'] ?? null) ? $context['loops'] : [];
        $max = min(Limits::int('max_loop_iterations'), (int) ($config['max_iterations'] ?? Limits::int('max_loop_iterations')));

        if (! isset($loops[$nodeId])) {
            if (isset($config['count'])) {
                $items = range(1, max(0, (int) $config['count']));
            } else {
                $source = (string) ($config['items'] ?? '');
                $items = str_contains($source, '{{')
                    ? $this->variables->resolve($source, $resolution)
                    : $this->variables->lookup($source, $resolution)[1];

                if ($items === null) {
                    $items = [];
                }

                if (! is_array($items)) {
                    return StepOutcome::fail('loop_source', 'The loop source is not a list.');
                }

                $items = array_values($items);
            }

            if (count($items) > $max) {
                return StepOutcome::fail('loop_limit', "The loop has more than {$max} items.");
            }

            $loops[$nodeId] = ['items' => $items, 'index' => 0];
        } else {
            $loops[$nodeId]['index']++;
        }

        $state = $loops[$nodeId];
        $count = count($state['items']);

        if ($state['index'] >= $count) {
            unset($loops[$nodeId]);
            $context['loops'] = $loops;
            $context['loop'] = null;

            return StepOutcome::proceed(['iterations' => $count], ['done']);
        }

        $context['loops'] = $loops;
        $context['loop'] = ['item' => $state['items'][$state['index']], 'index' => $state['index'], 'count' => $count];

        return StepOutcome::proceed(['index' => $state['index'], 'count' => $count], ['body']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function errorHandler(array $config, array &$context): StepOutcome
    {
        if (($config['then'] ?? 'resume') === 'fail' && is_array($context['error'] ?? null)) {
            $context['fail_at_end'] = $context['error'];
        }

        return StepOutcome::proceed(['handled' => true, 'error' => $context['error'] ?? null]);
    }

    // ------------------------------------------------------------------ applying an outcome

    /**
     * @param  array<string, mixed>  $context
     */
    private function apply(Run $run, Workflow $workflow, Graph $graph, RunStep $step, StepOutcome $outcome, array &$context, int $millis = 0): void
    {
        $node = $graph->node($step->node_id);
        $now = Carbon::now();

        DB::transaction(function () use ($run, $workflow, $graph, $step, $outcome, &$context, $node, $now, $millis): void {
            $log = [
                'at' => $now->toIso8601String(),
                'attempt' => $step->attempt,
                'outcome' => $outcome->kind,
                'duration_ms' => $millis,
            ];

            $logs = is_array($step->logs) ? $step->logs : [];
            $input = $outcome->input === [] ? ($step->input ?? null) : $this->redactor->redact($outcome->input);
            $output = $this->redactor->redact($outcome->output);

            switch ($outcome->kind) {
                case StepOutcome::PROCEED:
                    $context['steps'][$step->node_id] = $this->bounded($outcome->output);
                    $step->forceFill([
                        'status' => StepStatus::Succeeded, 'input' => $input, 'output' => $output,
                        'logs' => [...$logs, $log], 'finished_at' => $now, 'error_code' => null, 'error_message' => null,
                    ])->save();
                    $this->count($run);
                    $this->spawn($run, $graph, $step, $outcome->handles, $context);

                    break;

                case StepOutcome::WAIT:
                case StepOutcome::APPROVAL:
                    $step->forceFill([
                        'status' => StepStatus::Waiting, 'input' => $input, 'output' => $output,
                        'resume_at' => $outcome->until, 'logs' => [...$logs, [...$log, 'resume_at' => $outcome->until?->toIso8601String()]],
                    ])->save();
                    $this->count($run);
                    $context['steps'][$step->node_id] = $this->bounded($outcome->output);

                    break;

                case StepOutcome::END:
                    $step->forceFill([
                        'status' => StepStatus::Succeeded, 'input' => $input, 'output' => $output,
                        'logs' => [...$logs, $log], 'finished_at' => $now,
                    ])->save();
                    $this->count($run);

                    if ($outcome->endStatus === 'failed') {
                        $this->persistContext($run, $context);
                        $this->finish($run, RunStatus::Failed, 'end_failed', $outcome->message);

                        return;
                    }

                    $this->closeBranch($run, $context, $step->scope, false);

                    break;

                default:
                    $this->fail($run, $workflow, $graph, $step, $node, $outcome, $context, $logs, $log, $input);

                    break;
            }

            if (! $run->status->isTerminal()) {
                $this->persistContext($run, $context);
            }
        });

        if (! $run->status->isTerminal() && $this->tooLarge($context)) {
            $this->finish($run, RunStatus::Failed, 'context_too_large', 'The run data grew beyond the allowed size.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{id: string, type: NodeType, label: string, config: array<string, mixed>, position: array{x: float|int, y: float|int}}|null  $node
     * @param  list<array<string, mixed>>  $logs
     * @param  array<string, mixed>  $log
     * @param  array<string, mixed>|null  $input
     */
    private function fail(Run $run, Workflow $workflow, Graph $graph, RunStep $step, ?array $node, StepOutcome $outcome, array &$context, array $logs, array $log, ?array $input): void
    {
        $message = Str::limit($outcome->message, 500, '...');
        $log = [...$log, 'code' => $outcome->code, 'message' => $message];
        $retry = is_array($node['config']['retry'] ?? null) ? $node['config']['retry'] : [];
        $isRetryable = $node !== null && in_array($node['type'], [NodeType::Action, NodeType::Webhook], true);
        $attempts = $isRetryable ? min(Limits::int('max_attempts'), max(1, (int) ($retry['max_attempts'] ?? 3))) : 1;

        if (! $outcome->fatal && $outcome->retryable && $step->attempt < $attempts) {
            $backoff = min(
                Limits::int('max_backoff_seconds'),
                max(1, (int) ($retry['backoff_seconds'] ?? 30)) * (2 ** max(0, $step->attempt - 1)),
            );
            $step->forceFill([
                'status' => StepStatus::Pending,
                'available_at' => Carbon::now()->addSeconds($backoff),
                'error_code' => $outcome->code,
                'error_message' => $message,
                'input' => $input,
                'logs' => [...$logs, [...$log, 'retry_in_seconds' => $backoff]],
            ])->save();

            return;
        }

        $step->forceFill([
            'status' => StepStatus::Failed,
            'error_code' => $outcome->code,
            'error_message' => $message,
            'input' => $input,
            'logs' => [...$logs, $log],
            'finished_at' => Carbon::now(),
        ])->save();
        $this->count($run);

        $context['steps'][$step->node_id] = ['error' => $outcome->code];

        if (! $outcome->fatal && $node !== null && $node['type'] !== NodeType::ErrorHandler) {
            $routes = $graph->outgoing($step->node_id, 'error');
            $targets = array_map(static fn (array $edge): string => $edge['target'], $routes);

            if ($targets === [] && ($handler = $graph->globalErrorHandler()) !== null) {
                $targets = [$handler];
            }

            if ($targets !== []) {
                $context['error'] = ['node_id' => $step->node_id, 'code' => $outcome->code, 'message' => $message];

                foreach (array_unique($targets) as $target) {
                    $this->createStep($run, $graph, $target, $step->scope, $step->id, $context);
                }

                return;
            }
        }

        $this->persistContext($run, $context);
        $this->finish($run, RunStatus::Failed, $outcome->code, $message);
    }

    private function count(Run $run): void
    {
        $run->forceFill(['steps_executed' => $run->steps_executed + 1])->save();
    }

    // ------------------------------------------------------------------ the graph walk

    /**
     * @param  list<string|null>  $handles
     * @param  array<string, mixed>  $context
     */
    private function spawn(Run $run, Graph $graph, RunStep $from, array $handles, array &$context): void
    {
        $node = $graph->node($from->node_id);
        $edges = [];

        foreach ($handles as $handle) {
            array_push($edges, ...$graph->outgoing($from->node_id, $handle));
        }

        if ($edges === []) {
            $this->closeBranch($run, $context, $from->scope, false);

            return;
        }

        $scope = $from->scope;

        if ($node !== null && $node['type'] === NodeType::Parallel) {
            $joinNode = isset($node['config']['join_node']) && is_string($node['config']['join_node']) ? $node['config']['join_node'] : null;
            $context['joins'][$from->id] = [
                'expected' => count($edges), 'done' => 0, 'arrived' => 0, 'join_node' => $joinNode, 'outer' => $from->scope,
            ];
            $scope = $from->id;
        }

        foreach ($edges as $edge) {
            $join = $scope !== null ? ($context['joins'][$scope]['join_node'] ?? null) : null;

            // A branch reaching its parallel step's join node arrives instead of starting it twice.
            if ($join !== null && $edge['target'] === $join) {
                $this->closeBranch($run, $context, $scope, true);

                continue;
            }

            $this->createStep($run, $graph, $edge['target'], $scope, $from->id, $context);
        }
    }

    /**
     * A branch of a parallel step ended (or reached its join). When every branch has, the join node starts,
     * or - with no join - the parallel step itself counts as finished for the scope around it.
     *
     * @param  array<string, mixed>  $context
     */
    private function closeBranch(Run $run, array &$context, ?string $scope, bool $arrived): void
    {
        while ($scope !== null && isset($context['joins'][$scope])) {
            $join = &$context['joins'][$scope];
            $join['done']++;

            if ($arrived) {
                $join['arrived']++;
            }

            if ($join['done'] < $join['expected']) {
                return;
            }

            $outer = $join['outer'];
            $joinNode = $join['join_node'];
            $hadArrival = $join['arrived'] > 0;
            unset($join);

            if ($joinNode !== null && $hadArrival) {
                $this->createStep($run, $this->graphOf($run), $joinNode, $outer, $scope, $context);

                return;
            }

            // Nothing reached the join: the whole parallel step ended its own branch.
            $scope = $outer;
            $arrived = false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createStep(Run $run, Graph $graph, string $nodeId, ?string $scope, ?string $parentId, array &$context): RunStep
    {
        $type = $graph->node($nodeId)['type'] ?? NodeType::End;
        $context['seq'] = (int) ($context['seq'] ?? 0) + 1;

        return RunStep::query()->create([
            'run_id' => $run->id,
            'node_id' => $nodeId,
            'node_type' => $type->value,
            'status' => StepStatus::Pending,
            'sequence' => $context['seq'],
            'scope' => $scope,
            'parent_step_id' => $parentId,
        ]);
    }

    // ------------------------------------------------------------------ waits, approvals, settling

    /**
     * Resolve steps waiting on a delay or schedule that is now due.
     *
     * @param  array<string, mixed>  $context
     */
    private function wakeWaiting(Run $run, Workflow $workflow, Graph $graph, array &$context): bool
    {
        $due = RunStep::query()
            ->where('run_id', $run->id)
            ->where('status', StepStatus::Waiting->value)
            ->whereIn('node_type', [NodeType::Delay->value, NodeType::Schedule->value])
            ->where('resume_at', '<=', Carbon::now())
            ->orderBy('sequence')
            ->get();

        foreach ($due as $step) {
            DB::transaction(function () use ($run, $graph, $step, &$context): void {
                $step->forceFill([
                    'status' => StepStatus::Succeeded,
                    'finished_at' => Carbon::now(),
                    'logs' => [...(is_array($step->logs) ? $step->logs : []), ['at' => Carbon::now()->toIso8601String(), 'attempt' => $step->attempt, 'outcome' => 'resumed']],
                ])->save();
                $this->spawn($run, $graph, $step, [null], $context);
                $this->persistContext($run, $context);
            });
        }

        return $due->isNotEmpty();
    }

    /**
     * Apply decided (and expired) approvals to the steps that were waiting on them.
     *
     * @param  array<string, mixed>  $context
     */
    private function applyApprovals(Run $run, Workflow $workflow, Graph $graph, array &$context): bool
    {
        Approval::query()
            ->where('run_id', $run->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->where('expires_at', '<=', Carbon::now())
            ->update(['status' => ApprovalStatus::Expired->value, 'decided_at' => Carbon::now()]);

        $decided = Approval::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value, ApprovalStatus::Expired->value])
            ->get();

        $applied = false;

        foreach ($decided as $approval) {
            $step = RunStep::query()->find($approval->run_step_id);

            if ($step === null || $step->status !== StepStatus::Waiting) {
                continue;
            }

            $decision = match ($approval->status) {
                ApprovalStatus::Approved => 'approved',
                ApprovalStatus::Rejected => 'rejected',
                default => match ($approval->on_timeout) {
                    'approve' => 'approved',
                    'fail' => 'fail',
                    default => 'rejected',
                },
            };

            $context['approval'] = [
                'decision' => $decision, 'comment' => $approval->decision_comment,
                'decided_by' => $approval->decided_by_membership_id, 'timed_out' => $approval->status === ApprovalStatus::Expired,
            ];

            $outcome = $decision === 'fail'
                ? StepOutcome::fail('approval_timeout', 'Nobody decided the approval in time.')
                : StepOutcome::proceed($context['approval'], [$decision]);

            // The approval is not a new step: it completes the one that created it.
            $step->forceFill(['status' => StepStatus::Running, 'attempt' => max(1, $step->attempt)])->save();
            $this->apply($run, $workflow, $graph, $step, $outcome, $context);
            $applied = true;

            if ($run->status->isTerminal()) {
                break;
            }
        }

        return $applied;
    }

    /**
     * Nothing is ready: decide whether the run is waiting, or finished.
     *
     * @param  array<string, mixed>  $context
     */
    private function settle(Run $run, Workflow $workflow, array $context): bool
    {
        $open = RunStep::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [StepStatus::Pending->value, StepStatus::Waiting->value])
            ->get();

        if ($open->isEmpty()) {
            $failure = $context['fail_at_end'] ?? null;

            if (is_array($failure)) {
                $this->finish($run, RunStatus::Failed, (string) ($failure['code'] ?? 'step_failed'), (string) ($failure['message'] ?? ''));
            } else {
                $this->finish($run, RunStatus::Succeeded, null, null);
            }

            return false;
        }

        $waitingOnApproval = $open->every(fn (RunStep $step): bool => $step->status === StepStatus::Waiting && $step->node_type === NodeType::Approval->value);
        $times = $open->map(fn (RunStep $step) => $step->status === StepStatus::Pending ? $step->available_at : $step->resume_at)->filter();

        $run->forceFill([
            'status' => $waitingOnApproval ? RunStatus::AwaitingApproval : RunStatus::Waiting,
            'resume_at' => $times->min(),
        ])->save();

        return false;
    }

    // ------------------------------------------------------------------ finishing

    private function finish(Run $run, RunStatus $status, ?string $code, ?string $message): void
    {
        if ($run->fresh()?->status->isTerminal()) {
            return;
        }

        DB::transaction(function () use ($run, $status, $code, $message): void {
            RunStep::query()->where('run_id', $run->id)
                ->whereIn('status', [StepStatus::Pending->value, StepStatus::Waiting->value, StepStatus::Running->value])
                ->update([
                    'status' => StepStatus::Cancelled->value,
                    'finished_at' => Carbon::now(),
                    // Lets a manual retry of a failed run put back exactly the steps the failure stopped.
                    'meta' => json_encode(['stopped_by_run_end' => true]),
                ]);

            Approval::query()->where('run_id', $run->id)->where('status', ApprovalStatus::Pending->value)
                ->update(['status' => ApprovalStatus::Cancelled->value]);

            $run->forceFill([
                'status' => $status,
                'error_code' => $code,
                'error_message' => $message === null ? null : Str::limit($message, 1000, '...'),
                'finished_at' => Carbon::now(),
                'resume_at' => null,
            ])->save();

            if (! $run->isDryRun()) {
                Workflow::query()->whereKey($run->workflow_id)->update([
                    'last_run_at' => Carbon::now(),
                    'last_run_status' => $status->value,
                ]);

                RunFinished::dispatch([
                    'run_id' => $run->id, 'workflow_id' => $run->workflow_id,
                    'status' => $status->value, 'error_code' => $code,
                ]);

                if ($status === RunStatus::Failed) {
                    RunFailed::dispatch(['run_id' => $run->id, 'workflow_id' => $run->workflow_id, 'error_code' => $code]);
                }
            }
        });
    }

    // ------------------------------------------------------------------ helpers

    /** @param  array<string, mixed>  $context */
    private function persistContext(Run $run, array $context): void
    {
        $run->forceFill(['context' => $context])->save();
    }

    /** @param  array<string, mixed>  $context */
    private function tooLarge(array $context): bool
    {
        return strlen((string) json_encode($context)) > Limits::int('max_context_bytes');
    }

    /**
     * Step outputs kept in the run context are cut to a sane size; the full value was already handed on.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function bounded(array $output): array
    {
        return strlen((string) json_encode($output)) > 32768 ? ['truncated' => true] : $output;
    }

    private function failureOf(Throwable $exception): StepOutcome
    {
        if ($exception instanceof ValidationException) {
            return StepOutcome::fail('action_rejected', collect($exception->errors())->flatten()->first() ?? 'The action rejected its input.');
        }

        if ($exception instanceof ModelNotFoundException) {
            return StepOutcome::fail('not_found', 'A record the step refers to does not exist.');
        }

        if ($exception instanceof AuthorizationException
            || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500)) {
            return StepOutcome::fail('action_forbidden', 'The action was refused.');
        }

        report($exception);

        return StepOutcome::fail('action_error', class_basename($exception).': '.Str::limit($exception->getMessage(), 300, '...'), retryable: true);
    }

    private function graphOf(Run $run): Graph
    {
        $raw = $run->graph ?? WorkflowVersion::query()->findOrFail($run->version_id)->graph;

        return Graph::parse($raw)[0];
    }
}
