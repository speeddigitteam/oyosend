<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use App\Core\Tenancy\TenantContext;
use App\Modules\Automation\Enums\ApprovalStatus;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Jobs\ExecuteRun;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The engine's heartbeat, run every minute for each entitled workspace.
 *
 * Nothing about a run is held in memory or in a queue payload, so this is what makes a long delay, an
 * approval timeout, a retry backoff or a crashed worker recoverable: it finds every run that should be
 * moving and queues the executor for it. It also fires due cron schedules.
 */
final class Sweeper
{
    private const int BATCH = 200;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{dispatched: int, scheduled: int}
     */
    public function sweep(): array
    {
        return ['dispatched' => $this->dispatchDueRuns(), 'scheduled' => $this->fireSchedules()];
    }

    private function dispatchDueRuns(): int
    {
        $now = Carbon::now();

        $ids = Run::query()
            ->whereNotIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value, RunStatus::Cancelled->value])
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where('cancel_requested', true)
                    ->orWhere(fn (Builder $q) => $q->whereIn('status', [RunStatus::Waiting->value, RunStatus::AwaitingApproval->value])->where('resume_at', '<=', $now))
                    ->orWhere(fn (Builder $q) => $q->where('status', RunStatus::Queued->value)->where('updated_at', '<=', $now->copy()->subSeconds(30)))
                    ->orWhere(fn (Builder $q) => $q->where('status', RunStatus::Running->value)->where(fn (Builder $l) => $l->whereNull('locked_until')->orWhere('locked_until', '<=', $now)))
                    ->orWhereExists(function ($sub) use ($now): void {
                        // An approval that has been decided (or expired) but not yet applied to its step.
                        $sub->select(DB::raw(1))
                            ->from('automation_approvals as a')
                            ->join('automation_run_steps as s', 's.id', '=', 'a.run_step_id')
                            ->whereColumn('a.run_id', 'automation_runs.id')
                            ->where('s.status', StepStatus::Waiting->value)
                            ->where(function ($approval) use ($now): void {
                                $approval->whereIn('a.status', [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value, ApprovalStatus::Expired->value])
                                    ->orWhere(fn ($pending) => $pending->where('a.status', ApprovalStatus::Pending->value)->where('a.expires_at', '<=', $now));
                            });
                    });
            })
            ->orderBy('updated_at')
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($ids as $id) {
            ExecuteRun::dispatch((string) $id);
        }

        return $ids->count();
    }

    private function fireSchedules(): int
    {
        $due = Workflow::query()
            ->where('status', WorkflowStatus::Active->value)
            ->where('trigger_key', 'automation.trigger.schedule')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', Carbon::now())
            ->orderBy('next_run_at')
            ->limit(self::BATCH)
            ->get();

        $timezone = $this->tenants->require()->timezone ?: 'UTC';
        $starter = app(RunStarter::class);
        $fired = 0;

        foreach ($due as $workflow) {
            $slot = $workflow->next_run_at;

            if ($slot === null) {
                continue;
            }

            $config = is_array($workflow->trigger_config) ? $workflow->trigger_config : [];
            $next = CronSchedule::next((string) ($config['cron'] ?? ''), $timezone, CarbonImmutable::now());

            // Advance first: a missed slot is skipped, never replayed in a burst.
            $workflow->forceFill(['next_run_at' => $next === null ? null : Carbon::instance($next)])->save();

            $version = $workflow->published_version_id === null ? null : WorkflowVersion::query()->find($workflow->published_version_id);

            if ($version === null || $version->status !== VersionStatus::Published) {
                continue;
            }

            $run = $starter->start(
                $workflow,
                $version,
                RunSource::Schedule,
                'sched:'.$slot->clone()->utc()->format('YmdHi'),
                ['scheduled_for' => $slot->clone()->utc()->toIso8601String()],
            );

            $fired += $run === null ? 0 : 1;
        }

        return $fired;
    }
}
