<?php

declare(strict_types=1);

namespace App\Modules\Talent\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Talent\Actions\ReminderProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Announces probation reviews coming due and certifications expiring or expired (each fact once). */
final class SendReminders implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(ReminderProcessor $runner): void
    {
        $runner->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:talent', 'schedule:talent.reminders'];
    }
}
