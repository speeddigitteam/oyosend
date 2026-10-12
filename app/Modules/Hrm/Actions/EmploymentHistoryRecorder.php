<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Hrm\Enums\HistoryEvent;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmploymentHistory;

/** Appends to the immutable employment ledger. Call inside the transaction of the change itself. */
final class EmploymentHistoryRecorder
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function record(
        Employee $employee,
        HistoryEvent $event,
        string $effectiveOn,
        array $changes,
        ?string $reason = null,
        ?TenantMembership $actor = null,
    ): EmploymentHistory {
        return EmploymentHistory::query()->create([
            'employee_id' => $employee->id,
            'event' => $event,
            'effective_on' => $effectiveOn,
            'changes' => $changes,
            'reason' => $reason,
            'actor_id' => $actor?->id,
        ]);
    }
}
