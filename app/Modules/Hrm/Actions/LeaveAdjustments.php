<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\LedgerKind;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manual balance corrections (opening balances, grants, deductions). They are new
 * ledger rows with a note and the actor; nothing is edited. An automation retry
 * passes a stable key so the grant posts once.
 */
final class LeaveAdjustments
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HrmSettings $settings,
        private readonly LeaveBalances $balances,
    ) {}

    public function adjust(
        Employee $employee,
        LeaveType $type,
        int $units,
        string $note,
        ?int $year = null,
        ?string $idempotencyKey = null,
        ?TenantMembership $actor = null,
    ): LeaveLedgerEntry {
        if ($units === 0) {
            throw ValidationException::withMessages(['units' => __('The adjustment cannot be zero.')]);
        }

        if (abs($units) > 366 * 2) {
            throw ValidationException::withMessages(['units' => __('The adjustment is too large.')]);
        }

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => __('A note is required.')]);
        }

        if (! $type->tracks_balance) {
            throw ValidationException::withMessages(['leave_type_id' => __('This leave type does not keep a balance.')]);
        }

        $today = $this->settings->today()->toDateString();
        $year ??= $this->settings->leaveYear($today);
        $key = $idempotencyKey !== null ? 'adjustment:'.$idempotencyKey : 'adjustment:'.Str::ulid();

        return DB::transaction(function () use ($employee, $type, $units, $note, $year, $key, $actor, $today): LeaveLedgerEntry {
            /** @var Employee $locked */
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === EmployeeStatus::Terminated) {
                throw ValidationException::withMessages(['employee_id' => __('A terminated employee has no leave balance to change.')]);
            }

            if ($units < 0 && $this->balances->balance($locked->id, $type->id, $year) + $units < 0) {
                throw ValidationException::withMessages(['units' => __('The balance cannot go below zero.')]);
            }

            $entry = $this->balances->append($locked->id, $type->id, $year, $units, LedgerKind::Adjustment, $today, $key, null, trim($note), $actor?->id);
            $this->audit->record('hrm.leave.balance_adjusted', $locked, ['leave_type_id' => $type->id, 'leave_year' => $year, 'units' => $units, 'note' => trim($note)]);

            return $entry;
        });
    }
}
