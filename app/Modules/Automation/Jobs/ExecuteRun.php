<?php

declare(strict_types=1);

namespace App\Modules\Automation\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Automation\Engine\RunExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Advances one run, a persisted step at a time.
 *
 * Every step is committed (with its successors) before the next begins, so stopping here at any moment
 * loses nothing: the run row holds the whole state and `SweepRuns` re-dispatches anything left behind.
 * A job executes a few steps and then queues itself again so one long run cannot hog a worker.
 */
final class ExecuteRun implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int STEPS_PER_JOB = 10;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('automation');
    }

    public function handle(RunExecutor $executor): void
    {
        for ($i = 0; $i < self::STEPS_PER_JOB; $i++) {
            if (! $executor->advance($this->runId)) {
                return;
            }
        }

        self::dispatch($this->runId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:automation', 'run:'.$this->runId];
    }
}
