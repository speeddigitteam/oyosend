<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\LeaveAccrual;
use App\Modules\Hrm\Enums\LedgerKind;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Grants leave entitlement into the ledger.
 *
 * Every grant has a deterministic idempotency key (employee, leave type, leave year,
 * period), so running it twice, from two workers, or after a restart posts nothing
 * twice and a missed day is caught up on the next run.
 *
 *  - `annual`: the whole year's units at the start of the leave year, pro-rated by whole
 *    months when the employee joined mid-year;
 *  - `monthly`: the year's units spread over twelve months with exact integer
 *    arithmetic (month n receives floor(annual*n/12) - floor(annual*(n-1)/12)), starting
 *    in the month the employee joined;
 *  - carry forward: on the first run of a new leave year the previous year's remaining
 *    balance is closed out (a `carry_out` row) and up to `carry_forward_max_units` of it
 *    is credited to the new year (a `carry_in` row); the rest lapses;
 *  - `max_balance_units` caps what accrual may credit (a capped period still posts a
 *    zero row so it is not retried).
 */
final class LeaveAccruals
{
    public function __construct(
        private readonly HrmSettings $settings,
        private readonly LeaveBalances $balances,
        private readonly LeavePolicies $policies,
    ) {}

    /** @return int Rows posted for every employee that is not terminated. */
    public function accrueAll(?CarbonImmutable $today = null): int
    {
        $posted = 0;

        Employee::query()
            ->where('status', '!=', EmployeeStatus::Terminated)
            ->chunkById(100, function ($employees) use ($today, &$posted): void {
                foreach ($employees as $employee) {
                    $posted += $this->accrueEmployee($employee, $today);
                }
            });

        return $posted;
    }

    /** @return int Rows posted. */
    public function accrueEmployee(Employee $employee, ?CarbonImmutable $today = null): int
    {
        if ($employee->status === EmployeeStatus::Terminated) {
            return 0;
        }

        $today ??= $this->settings->today();
        $posted = 0;

        $types = LeaveType::query()->where('is_active', true)->where('tracks_balance', true)->get();

        foreach ($types as $type) {
            $policy = $this->policies->forEmployee($employee, $type);

            if ($policy === null) {
                continue;
            }

            $posted += DB::transaction(fn (): int => $this->accrueType($employee, $type, $policy, $today));
        }

        return $posted;
    }

    private function accrueType(Employee $employee, LeaveType $type, LeavePolicy $policy, CarbonImmutable $today): int
    {
        $eligibleFrom = $employee->joined_on->toImmutable()->addDays($policy->min_service_days);

        if ($today->toDateString() < $eligibleFrom->toDateString() || $today->toDateString() < $employee->joined_on->toDateString()) {
            return 0;
        }

        $year = $this->settings->leaveYear($today->toDateString());
        $yearStart = $this->settings->leaveYearStart($year);
        $posted = $this->carryForward($employee, $type, $policy, $year, $yearStart);

        // The first month of the leave year (0-based) in which the employee is on the books.
        $joined = $employee->joined_on->toImmutable();
        $firstMonth = 0;

        if ($joined > $yearStart) {
            $firstMonth = min(11, ($joined->year - $yearStart->year) * 12 + $joined->month - $yearStart->month);
        }

        $annual = $policy->annual_units;
        $base = "accrual:{$employee->id}:{$type->id}:{$year}";

        if ($policy->accrual === LeaveAccrual::Annual) {
            $units = intdiv($annual * (12 - $firstMonth), 12);
            $posted += $this->credit($employee, $type, $policy, $year, $units, $yearStart->toDateString(), "{$base}:annual");

            return $posted;
        }

        for ($month = $firstMonth; $month < 12; $month++) {
            $monthStart = $yearStart->addMonthsNoOverflow($month);

            if ($monthStart > $today) {
                break;
            }

            $units = intdiv($annual * ($month + 1), 12) - intdiv($annual * $month, 12);
            $posted += $this->credit($employee, $type, $policy, $year, $units, $monthStart->toDateString(), $base.':m'.($month + 1));
        }

        return $posted;
    }

    private function credit(Employee $employee, LeaveType $type, LeavePolicy $policy, int $year, int $units, string $effectiveOn, string $key): int
    {
        if ($this->balances->hasKey($key)) {
            return 0;
        }

        $note = null;

        if ($policy->max_balance_units !== null) {
            $room = max(0, $policy->max_balance_units - $this->balances->balance($employee->id, $type->id, $year));

            if ($units > $room) {
                $units = $room;
                $note = 'Capped at the maximum balance';
            }
        }

        $this->balances->append($employee->id, $type->id, $year, $units, LedgerKind::Accrual, $effectiveOn, $key, null, $note);

        return 1;
    }

    private function carryForward(Employee $employee, LeaveType $type, LeavePolicy $policy, int $year, CarbonImmutable $yearStart): int
    {
        $inKey = "carry_in:{$employee->id}:{$type->id}:{$year}";

        if ($this->balances->hasKey($inKey)) {
            return 0;
        }

        $previous = $year - 1;
        $hadPrevious = LeaveLedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('leave_year', $previous)
            ->exists();

        if (! $hadPrevious) {
            return 0;
        }

        $remaining = $this->balances->balance($employee->id, $type->id, $previous);

        if ($remaining <= 0) {
            return 0;
        }

        $carry = min($remaining, $policy->carry_forward_max_units);
        $date = $yearStart->toDateString();
        $note = $remaining > $carry ? 'Carried forward up to the policy maximum; the rest lapsed' : null;

        $this->balances->append($employee->id, $type->id, $previous, -$remaining, LedgerKind::CarryOut, $date, "carry_out:{$employee->id}:{$type->id}:{$previous}", null, $note);
        $this->balances->append($employee->id, $type->id, $year, $carry, LedgerKind::CarryForward, $date, $inKey, null, $note);

        return 2;
    }
}
