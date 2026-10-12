<?php

declare(strict_types=1);

namespace App\Modules\Automation\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Automation\Engine\Sweeper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The module's scheduled job (`automation.sweep`, every minute, only for entitled workspaces): resumes due
 * delays, approval timeouts and retries, recovers abandoned runs and fires cron schedules.
 */
final class SweepRuns implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(Sweeper $sweeper): void
    {
        $sweeper->sweep();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:automation', 'schedule:automation.sweep'];
    }
}
