<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use App\Core\Billing\UsageLimits;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Automation\Enums\NodeType;
use App\Modules\Automation\Enums\RunMode;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Events\RunFailed;
use App\Modules\Automation\Events\RunFinished;
use App\Modules\Automation\Graph\Graph;
use App\Modules\Automation\Jobs\ExecuteRun;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Limits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The one place a run is created.
 *
 * It applies the volume limits (per workflow and per workspace), the loop-prevention depth limit and the
 * monthly run quota, makes creation idempotent per (workflow, key) so a redelivered event, webhook or
 * schedule slot never starts a second run, and queues the executor after the surrounding commit.
 */
final class RunStarter
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly UsageLimits $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  What the trigger hands to the workflow (`trigger.*`).
     * @param  array<string, mixed>|null  $graph  A snapshot to execute instead of the version's graph (test runs).
     * @return Run|null The new run; null when it was a duplicate or was dropped by the volume limits.
     */
    public function start(
        Workflow $workflow,
        WorkflowVersion $version,
        RunSource $source,
        string $idempotencyKey,
        array $payload = [],
        RunMode $mode = RunMode::Live,
        int $depth = 0,
        ?string $parentRunId = null,
        ?string $startedByMembershipId = null,
        ?array $graph = null,
    ): ?Run {
        $live = $mode === RunMode::Live;

        if ($live && $this->overVolume($workflow)) {
            Log::warning('Automation run dropped: volume limit', ['workflow' => $workflow->id]);

            return null;
        }

        $failure = null;

        if ($depth > Limits::int('max_depth')) {
            $failure = ['depth_limit', 'Workflows triggered each other too many times in a row.'];
        } elseif ($live && ! $this->limits->allows('automation.runs')) {
            $failure = ['quota_exceeded', 'The plan\'s monthly workflow run quota has been used.'];
        }

        try {
            $run = Run::query()->create([
                'workflow_id' => $workflow->id,
                'version_id' => $version->id,
                'version_number' => $version->number,
                'mode' => $mode,
                'source' => $source,
                'status' => $failure === null ? RunStatus::Queued : RunStatus::Failed,
                'trigger_key' => $workflow->trigger_key ?? $version->trigger_key,
                'idempotency_key' => $idempotencyKey,
                'depth' => min($depth, 255),
                'parent_run_id' => $parentRunId,
                'started_by_membership_id' => $startedByMembershipId,
                'graph' => $graph,
                'context' => [
                    'trigger' => $payload, 'steps' => [], 'vars' => [], 'loop' => null, 'error' => null,
                    'joins' => [], 'loops' => [], 'fail_at_end' => null, 'seq' => 0,
                ],
                'error_code' => $failure[0] ?? null,
                'error_message' => $failure[1] ?? null,
                'finished_at' => $failure === null ? null : Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($failure !== null) {
            if ($live) {
                $workflow->forceFill(['last_run_at' => Carbon::now(), 'last_run_status' => RunStatus::Failed->value])->save();
                RunFinished::dispatch(['run_id' => $run->id, 'workflow_id' => $workflow->id, 'status' => 'failed', 'error_code' => $failure[0]]);
                RunFailed::dispatch(['run_id' => $run->id, 'workflow_id' => $workflow->id, 'error_code' => $failure[0]]);
            }

            return $run;
        }

        // The run begins at the trigger node; everything after it is spawned by the executor.
        $trigger = Graph::parse($graph ?? $version->graph)[0]->trigger();

        if ($trigger === null) {
            $run->forceFill(['status' => RunStatus::Failed, 'error_code' => 'no_trigger', 'error_message' => 'The workflow has no trigger.', 'finished_at' => Carbon::now()])->save();

            return $run;
        }

        RunStep::query()->create([
            'run_id' => $run->id,
            'node_id' => $trigger['id'],
            'node_type' => NodeType::Trigger->value,
            'status' => StepStatus::Pending,
            'sequence' => 1,
        ]);
        $run->forceFill(['context' => [...($run->context ?? []), 'seq' => 1]])->save();

        $this->queue($run->id);

        return $run;
    }

    /** Queue the executor for a run once the surrounding transaction (if any) has committed. */
    public function queue(string $runId): void
    {
        $this->tenants->afterCommit(function (Tenant $tenant) use ($runId): void {
            ExecuteRun::dispatch($runId);
        });
    }

    private function overVolume(Workflow $workflow): bool
    {
        $since = Carbon::now()->subMinute();

        if (Run::query()->where('workflow_id', $workflow->id)->where('created_at', '>=', $since)->count() >= Limits::int('runs_per_minute_workflow')) {
            return true;
        }

        return Run::query()->where('created_at', '>=', $since)->count() >= Limits::int('runs_per_minute_tenant');
    }
}
