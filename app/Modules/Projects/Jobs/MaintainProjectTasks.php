<?php

declare(strict_types=1);

namespace App\Modules\Projects\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Projects\Actions\TaskManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Entitlement-gated scheduled maintenance for recurring tasks and reminder events. */
final class MaintainProjectTasks implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 300;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(TaskManager $tasks): void
    {
        $tasks->generateDueRecurrences();
        $tasks->emitDueReminders();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:projects', 'schedule:projects.task_maintenance'];
    }
}
