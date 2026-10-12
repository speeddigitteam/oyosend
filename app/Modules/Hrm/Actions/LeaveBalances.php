<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LedgerKind;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and appends the leave ledger. A balance is only ever the sum of rows; the
 * model refuses updates and deletes, and every append carries an idempotency key
 * (unique per workspace), so a retried job or request cannot post twice.
 */
final class LeaveBalances
{
    public function balance(string $employeeId, string $leaveTypeId, int $year): int
    {
        return (int) LeaveLedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('leave_year', $year)
            ->sum('units');
    }

    /**
     * Balance per leave type for one leave year.
     *
     * @return array<string, int> leave type id => units
     */
    public function forEmployee(string $employeeId, int $year): array
    {
        return LeaveLedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_year', $year)
            ->groupBy('leave_type_id')
            ->selectRaw('leave_type_id, SUM(units) as total')
            ->pluck('total', 'leave_type_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    public function hasKey(string $key): bool
    {
        return LeaveLedgerEntry::query()->where('idempotency_key', $key)->exists();
    }

    /**
     * Append one row. A repeated key returns the row already stored and writes nothing.
     */
    public function append(
        string $employeeId,
        string $leaveTypeId,
        int $year,
        int $units,
        LedgerKind $kind,
        string $effectiveOn,
        string $key,
        ?string $requestId = null,
        ?string $note = null,
        ?string $actorId = null,
    ): LeaveLedgerEntry {
        $existing = LeaveLedgerEntry::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): LeaveLedgerEntry => LeaveLedgerEntry::query()->create([
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'leave_year' => $year,
                'units' => $units,
                'kind' => $kind,
                'effective_on' => $effectiveOn,
                'leave_request_id' => $requestId,
                'idempotency_key' => $key,
                'note' => $note,
                'actor_id' => $actorId,
            ]));
        } catch (UniqueConstraintViolationException) {
            return LeaveLedgerEntry::query()->where('idempotency_key', $key)->firstOrFail();
        }
    }

    /** Refuse a debit that the balance cannot cover. */
    public function ensureAvailable(string $employeeId, string $leaveTypeId, int $year, int $units): void
    {
        if ($this->balance($employeeId, $leaveTypeId, $year) < $units) {
            throw ValidationException::withMessages(['starts_on' => __('The leave balance is not enough for this request.')]);
        }
    }
}
