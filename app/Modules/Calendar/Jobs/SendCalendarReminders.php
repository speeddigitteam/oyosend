<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Calendar\Actions\ReminderDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The module's scheduled job (`calendar.reminders`, every minute, only for entitled workspaces): sends the event
 * reminders that fell due. Safe to run twice; the notifier's dedupe key keeps each reminder to one.
 */
final class SendCalendarReminders implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** Seconds; below the notifications supervisor's worker timeout. */
    public int $timeout = 55;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(ReminderDispatcher $reminders): void
    {
        $reminders->dispatchDue(CarbonImmutable::now());
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:calendar', 'schedule:calendar.reminders'];
    }
}
