<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Hrm\Actions\LeaveAccruals;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Posts due leave accruals and carry-forwards (every row has an idempotency key). */
final class AccrueLeave implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(LeaveAccruals $accruals): void
    {
        $accruals->accrueAll();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:hrm', 'schedule:hrm.leave_accrual'];
    }
}
