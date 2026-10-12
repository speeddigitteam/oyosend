<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Hrm\Actions\AttendanceCloser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Closes the attendance days that ended (idempotent: a closed day is skipped). */
final class CloseAttendanceDays implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(AttendanceCloser $closer): void
    {
        $closer->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:hrm', 'schedule:hrm.attendance_close'];
    }
}
