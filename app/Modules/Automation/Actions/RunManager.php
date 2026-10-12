<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Automation\Engine\InvalidWorkflow;
use App\Modules\Automation\Engine\RunExecutor;
use App\Modules\Automation\Engine\RunStarter;
use App\Modules\Automation\Enums\RunMode;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Graph\GraphValidator;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Limits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starting runs by hand, test runs, cancelling and retrying.
 */
final class RunManager
{
    /** Failures that a retry cannot fix: the limit would be hit again, or the run never began. */
    private const array NOT_RETRYABLE = ['depth_limit', 'quota_exceeded', 'step_limit', 'run_timeout', 'context_too_large', 'workflow_missing'];

    private const int MAX_MANUAL_RETRIES = 5;

    public function __construct(
        private readonly RunStarter $starter,
        private readonly RunExecutor $executor,
        private readonly GraphValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start a live run of a published, manually triggered workflow.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function startManual(Workflow $workflow, TenantMembership $by, array $payload): Run
    {
        if (! $workflow->isLive() || $workflow->trigger_key !== 'automation.trigger.manual') {
            throw ValidationException::withMessages(['workflow' => __('This workflow cannot be started by hand.')]);
        }

        $this->assertPayload($payload);

        $version = WorkflowVersion::query()->findOrFail($workflow->published_version_id);

        if ($version->status !== VersionStatus::Published) {
            throw ValidationException::withMessages(['workflow' => __('This workflow has no published version.')]);
        }

        $run = $this->starter->start(
            $workflow, $version, RunSource::Manual, 'manual:'.Str::ulid(), $payload,
            startedByMembershipId: $by->id,
        );

        if ($run === null) {
            throw ValidationException::withMessages(['workflow' => __('Too many runs were started just now. Try again in a minute.')]);
        }

        $this->audit->record('automation.run.started', $run, ['workflow_id' => $workflow->id, 'source' => 'manual']);

        return $run;
    }

    /**
     * Execute the draft in dry-run mode: conditions, branches, loops and mappings really evaluate, but no
     * action, webhook or approval is performed and nothing waits.
     *
     * @param  array<string, mixed>  $payload  A sample of what the trigger would hand over.
     *
     * @throws ValidationException
     */
    public function test(Workflow $workflow, TenantMembership $by, array $payload): Run
    {
        $draft = $workflow->draft() ?? WorkflowVersion::query()->find($workflow->published_version_id);

        if ($draft === null) {
            throw ValidationException::withMessages(['workflow' => __('There is nothing to test yet.')]);
        }

        $this->assertPayload($payload);

        $report = $this->validator->validate($draft->graph, $by, false);

        if ($report->hasErrors()) {
            throw InvalidWorkflow::from($report);
        }

        $run = $this->starter->start(
            $workflow, $draft, RunSource::Test, 'test:'.Str::ulid(), $payload,
            mode: RunMode::DryRun,
            startedByMembershipId: $by->id,
            graph: $draft->graph,
        );

        if ($run === null) {
            throw ValidationException::withMessages(['workflow' => __('The test could not be started.')]);
        }

        return $run;
    }

    /**
     * @throws ValidationException
     */
    public function cancel(Run $run, TenantMembership $by): Run
    {
        $cancelled = DB::transaction(function () use ($run): Run {
            $fresh = Run::query()->lockForUpdate()->findOrFail($run->id);

            if ($fresh->status->isTerminal()) {
                throw ValidationException::withMessages(['run' => __('This run has already finished.')]);
            }

            if ($fresh->locked_until !== null && $fresh->locked_until->isFuture()) {
                // A worker is mid-step: it will see the flag and stop at the next step boundary.
                $fresh->forceFill(['cancel_requested' => true])->save();
            } else {
                $this->executor->cancel($fresh, 'cancelled', 'Cancelled by a member.');
            }

            $this->audit->record('automation.run.cancelled', $fresh, ['workflow_id' => $fresh->workflow_id]);

            return $fresh;
        });

        $this->starter->queue($cancelled->id);

        return $cancelled;
    }

    /**
     * Resume a failed run from the step that failed. Steps that already succeeded do not run again, and a
     * step that is retried reuses its idempotency key, so an action that did complete is not repeated.
     *
     * @throws ValidationException
     */
    public function retry(Run $run, TenantMembership $by): Run
    {
        $retried = DB::transaction(function () use ($run): Run {
            $fresh = Run::query()->lockForUpdate()->findOrFail($run->id);
            $workflow = Workflow::query()->find($fresh->workflow_id);

            if ($fresh->status !== RunStatus::Failed || $fresh->isDryRun()
                || in_array($fresh->error_code, self::NOT_RETRYABLE, true)
                || $fresh->retry_count >= self::MAX_MANUAL_RETRIES
                || $workflow === null || $workflow->status === WorkflowStatus::Archived) {
                throw ValidationException::withMessages(['run' => __('This run cannot be retried.')]);
            }

            RunStep::query()->where('run_id', $fresh->id)->where('status', StepStatus::Failed->value)->update([
                'status' => StepStatus::Pending->value, 'attempt' => 0, 'available_at' => null,
                'error_code' => null, 'error_message' => null, 'finished_at' => null,
            ]);

            RunStep::query()->where('run_id', $fresh->id)->where('status', StepStatus::Cancelled->value)
                ->where('meta', 'like', '%stopped_by_run_end%')
                ->update(['status' => StepStatus::Pending->value, 'meta' => null, 'finished_at' => null]);

            $context = is_array($fresh->context) ? $fresh->context : [];
            $context['fail_at_end'] = null;
            $context['error'] = null;

            $fresh->forceFill([
                'status' => RunStatus::Queued,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => null,
                'resume_at' => null,
                'locked_until' => null,
                'cancel_requested' => false,
                'retry_count' => $fresh->retry_count + 1,
                'started_at' => $fresh->started_at ?? Carbon::now(),
                'context' => $context,
            ])->save();

            $this->audit->record('automation.run.retried', $fresh, ['workflow_id' => $fresh->workflow_id]);

            return $fresh;
        });

        $this->starter->queue($retried->id);

        return $retried;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function assertPayload(array $payload): void
    {
        if (strlen((string) json_encode($payload)) > Limits::int('max_payload_bytes')) {
            throw ValidationException::withMessages(['payload' => __('The payload is too large.')]);
        }
    }
}
