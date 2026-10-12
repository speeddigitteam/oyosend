<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Automation\Engine\CronSchedule;
use App\Modules\Automation\Engine\InvalidWorkflow;
use App\Modules\Automation\Enums\NodeType;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Events\WorkflowArchived;
use App\Modules\Automation\Events\WorkflowPublished;
use App\Modules\Automation\Graph\Graph;
use App\Modules\Automation\Graph\GraphHasher;
use App\Modules\Automation\Graph\GraphValidator;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Limits;
use App\Modules\Automation\Support\Templates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of a workflow: create, edit the draft, publish an immutable version, pause, archive.
 *
 * Only a published version executes. Publishing validates the whole graph strictly - including that the
 * publisher holds every permission and the workspace every app the steps need - and records the publisher
 * as the member the workflow runs as. Every change is audited in the same transaction.
 */
final class WorkflowManager
{
    public function __construct(
        private readonly GraphValidator $validator,
        private readonly UsageLimits $limits,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array<string, mixed>|null  $graph  Defaults to a blank manual workflow.
     */
    public function create(string $name, ?string $description, TenantMembership $by, ?array $graph = null): Workflow
    {
        return DB::transaction(function () use ($name, $description, $by, $graph): Workflow {
            $workflow = Workflow::query()->create([
                'name' => mb_substr(trim($name), 0, 160),
                'description' => $description === null || trim($description) === '' ? null : mb_substr(trim($description), 0, 2000),
                'status' => WorkflowStatus::Draft,
                'created_by_membership_id' => $by->id,
            ]);

            $this->writeDraft($workflow, $graph ?? Templates::blank(), $by);

            $this->audit->record('automation.workflow.created', $workflow);

            return $workflow;
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, description
     */
    public function update(Workflow $workflow, array $data): Workflow
    {
        $workflow->fill([
            'name' => mb_substr(trim((string) ($data['name'] ?? $workflow->name)), 0, 160),
            'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                ? mb_substr(trim((string) $data['description']), 0, 2000) : null,
        ])->save();

        $this->audit->record('automation.workflow.updated', $workflow);

        return $workflow;
    }

    /**
     * Save the editable draft. A draft may be incomplete or invalid; only publishing requires validity.
     *
     * @param  array<string, mixed>  $graph
     *
     * @throws ValidationException
     */
    public function saveDraft(Workflow $workflow, array $graph, TenantMembership $by): WorkflowVersion
    {
        return DB::transaction(function () use ($workflow, $graph, $by): WorkflowVersion {
            $locked = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);

            if ($locked->status === WorkflowStatus::Archived) {
                throw ValidationException::withMessages(['graph' => __('An archived workflow cannot be edited.')]);
            }

            return $this->writeDraft($locked, $graph, $by);
        });
    }

    /**
     * Put an earlier version's graph into the draft so it can be edited and published again.
     */
    public function restore(Workflow $workflow, WorkflowVersion $source, TenantMembership $by): WorkflowVersion
    {
        return DB::transaction(function () use ($workflow, $source, $by): WorkflowVersion {
            $locked = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);

            if ($locked->status === WorkflowStatus::Archived) {
                throw ValidationException::withMessages(['graph' => __('An archived workflow cannot be edited.')]);
            }

            $draft = $this->writeDraft($locked, $source->graph, $by);
            $this->audit->record('automation.workflow.restored', $locked, ['from_version' => $source->number, 'draft' => $draft->number]);

            return $draft;
        });
    }

    public function duplicate(Workflow $workflow, TenantMembership $by): Workflow
    {
        $source = $workflow->draft() ?? WorkflowVersion::query()->find($workflow->published_version_id);

        return $this->create(
            Str::limit($workflow->name, 150, '').' ('.__('copy').')',
            $workflow->description,
            $by,
            $source?->graph,
        );
    }

    /**
     * Publish the draft as a new immutable version and make it the live one.
     *
     * @return array{version: WorkflowVersion, credentials: array{token: string, secret: string}|null}
     *                                                                                                 `credentials` is set only when this publish created webhook credentials: shown once.
     *
     * @throws ValidationException
     */
    public function publish(Workflow $workflow, TenantMembership $by, ?string $note = null): array
    {
        return DB::transaction(function () use ($workflow, $by, $note): array {
            $locked = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);

            if ($locked->status === WorkflowStatus::Archived) {
                throw ValidationException::withMessages(['graph' => __('An archived workflow cannot be published.')]);
            }

            $draft = $locked->draft();

            if ($draft === null) {
                throw ValidationException::withMessages(['graph' => __('There are no unpublished changes.')]);
            }

            $report = $this->validator->validate($draft->graph, $by, true);

            if ($report->hasErrors()) {
                throw InvalidWorkflow::from($report);
            }

            if ($locked->status !== WorkflowStatus::Active) {
                $this->limits->ensure('automation.workflows', 1, 'graph');
            }

            $graph = $report->graph->toArray();
            $trigger = $report->graph->trigger();
            $triggerKey = (string) ($trigger['config']['trigger_key'] ?? '');
            $triggerConfig = is_array($trigger['config']['config'] ?? null) ? $trigger['config']['config'] : [];

            foreach ($locked->versions()->where('status', VersionStatus::Published->value)->get() as $previous) {
                $previous->forceFill(['status' => VersionStatus::Archived, 'archived_at' => Carbon::now()])->save();
            }

            $draft->forceFill([
                'status' => VersionStatus::Published,
                'graph' => $graph,
                'graph_hash' => GraphHasher::hash($graph),
                'trigger_key' => $triggerKey,
                'trigger_config' => $triggerConfig,
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 255),
                'published_by_membership_id' => $by->id,
                'published_at' => Carbon::now(),
            ])->save();

            $credentials = null;
            $attributes = [];

            if ($triggerKey === 'automation.trigger.webhook') {
                if ($locked->webhook_token_hash === null) {
                    $credentials = $this->credentials();
                    $attributes = [
                        'webhook_token_hash' => hash('sha256', $credentials['token']),
                        'webhook_secret' => $credentials['secret'],
                    ];
                }
            } else {
                $attributes = ['webhook_token_hash' => null, 'webhook_secret' => null];
            }

            $locked->forceFill([
                ...$attributes,
                'status' => WorkflowStatus::Active,
                'published_version_id' => $draft->id,
                'actor_membership_id' => $by->id,
                'trigger_key' => $triggerKey,
                'trigger_config' => $triggerConfig,
                'published_at' => Carbon::now(),
                'next_run_at' => $this->nextRun($triggerKey, $triggerConfig),
            ])->save();

            $this->audit->record('automation.workflow.published', $locked, [
                'version' => $draft->number,
                'graph_hash' => $draft->graph_hash,
                'trigger' => $triggerKey,
            ]);

            WorkflowPublished::dispatch([
                'workflow_id' => $locked->id,
                'version_id' => $draft->id,
                'version' => $draft->number,
                'actor_membership_id' => $by->id,
            ]);

            return ['version' => $draft, 'credentials' => $credentials];
        });
    }

    /** Stop new runs from starting. Runs already in flight finish. */
    public function pause(Workflow $workflow): Workflow
    {
        if ($workflow->status !== WorkflowStatus::Active) {
            throw ValidationException::withMessages(['workflow' => __('Only an active workflow can be paused.')]);
        }

        $workflow->forceFill(['status' => WorkflowStatus::Paused, 'next_run_at' => null])->save();
        $this->audit->record('automation.workflow.paused', $workflow);

        return $workflow;
    }

    public function resume(Workflow $workflow, TenantMembership $by): Workflow
    {
        if ($workflow->status !== WorkflowStatus::Paused || $workflow->published_version_id === null) {
            throw ValidationException::withMessages(['workflow' => __('Only a paused workflow can be resumed.')]);
        }

        $version = WorkflowVersion::query()->findOrFail($workflow->published_version_id);
        $report = $this->validator->validate($version->graph, $by, true);

        if ($report->hasErrors()) {
            throw InvalidWorkflow::from($report);
        }

        $this->limits->ensure('automation.workflows', 1, 'workflow');

        $workflow->forceFill([
            'status' => WorkflowStatus::Active,
            // Resuming is re-publishing responsibility: the workflow now runs as whoever resumed it.
            'actor_membership_id' => $by->id,
            'next_run_at' => $this->nextRun((string) $workflow->trigger_key, $workflow->trigger_config ?? []),
        ])->save();
        $this->audit->record('automation.workflow.resumed', $workflow);

        return $workflow;
    }

    /** Retire the workflow: its in-flight runs are cancelled and nothing starts again. */
    public function archive(Workflow $workflow): Workflow
    {
        DB::transaction(function () use ($workflow): void {
            foreach ($workflow->versions()->where('status', VersionStatus::Published->value)->get() as $version) {
                $version->forceFill(['status' => VersionStatus::Archived, 'archived_at' => Carbon::now()])->save();
            }

            $workflow->forceFill([
                'status' => WorkflowStatus::Archived,
                'trigger_key' => null,
                'trigger_config' => null,
                'next_run_at' => null,
                'webhook_token_hash' => null,
                'webhook_secret' => null,
            ])->save();

            // The executor sees the archived workflow and cancels each run at its next pass.
            Run::query()->where('workflow_id', $workflow->id)
                ->whereNotIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value, RunStatus::Cancelled->value])
                ->update(['cancel_requested' => true, 'resume_at' => Carbon::now()]);

            $this->audit->record('automation.workflow.archived', $workflow);
            WorkflowArchived::dispatch(['workflow_id' => $workflow->id]);
        });

        return $workflow;
    }

    /**
     * Replace the inbound webhook's token and signing secret. The old ones stop working immediately.
     *
     * @return array{token: string, secret: string}
     */
    public function rotateWebhook(Workflow $workflow): array
    {
        if ($workflow->trigger_key !== 'automation.trigger.webhook' || $workflow->status === WorkflowStatus::Archived) {
            throw ValidationException::withMessages(['workflow' => __('This workflow does not receive webhooks.')]);
        }

        $credentials = $this->credentials();

        $workflow->forceFill([
            'webhook_token_hash' => hash('sha256', $credentials['token']),
            'webhook_secret' => $credentials['secret'],
        ])->save();

        $this->audit->record('automation.workflow.webhook_rotated', $workflow);

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function writeDraft(Workflow $workflow, array $raw, TenantMembership $by): WorkflowVersion
    {
        if (strlen((string) json_encode($raw)) > Limits::int('max_graph_bytes')) {
            throw ValidationException::withMessages(['graph' => __('The workflow is too large.')]);
        }

        [$graph, $problems] = Graph::parse($raw);

        if ($problems !== []) {
            throw ValidationException::withMessages(['graph' => __('The graph is malformed: :problem.', ['problem' => $problems[0]])]);
        }

        if (count($graph->nodes) > Limits::int('max_nodes') || count($graph->edges) > Limits::int('max_edges')) {
            throw ValidationException::withMessages(['graph' => __('The workflow is too large.')]);
        }

        $normalized = $graph->toArray();
        $trigger = $graph->trigger();
        $draft = $workflow->draft();

        $attributes = [
            'graph' => $normalized,
            'schema_version' => Graph::SCHEMA_VERSION,
            'trigger_key' => is_string($trigger['config']['trigger_key'] ?? null) ? $trigger['config']['trigger_key'] : null,
            'trigger_config' => is_array($trigger['config']['config'] ?? null) ? $trigger['config']['config'] : null,
        ];

        if ($draft !== null) {
            $draft->forceFill($attributes)->save();

            return $draft;
        }

        return WorkflowVersion::query()->create([
            ...$attributes,
            'workflow_id' => $workflow->id,
            'number' => (int) $workflow->versions()->max('number') + 1,
            'status' => VersionStatus::Draft,
            'created_by_membership_id' => $by->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function nextRun(string $triggerKey, array $config): ?Carbon
    {
        if ($triggerKey !== 'automation.trigger.schedule') {
            return null;
        }

        $next = CronSchedule::next((string) ($config['cron'] ?? ''), $this->tenants->require()->timezone ?: 'UTC', CarbonImmutable::now());

        return $next === null ? null : Carbon::instance($next);
    }

    /** @return array{token: string, secret: string} */
    private function credentials(): array
    {
        return ['token' => 'whk_'.Str::random(40), 'secret' => 'whsec_'.Str::random(48)];
    }

    /** Whether the graph of a version uses a node type; handy for presenters and tests. */
    public static function uses(Graph $graph, NodeType $type): bool
    {
        foreach ($graph->nodes as $node) {
            if ($node['type'] === $type) {
                return true;
            }
        }

        return false;
    }
}
