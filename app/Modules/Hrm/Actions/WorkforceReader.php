<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Contracts\EmployeeTimeSummary;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The implementation of the cross-module read contracts. It returns plain data
 * (snapshots and arrays), never an HRM model, and is the only door other modules use.
 */
final class WorkforceReader implements EmployeeDirectory, EmployeeTimeSummary
{
    public function find(string $employeeId): ?EmployeeSnapshot
    {
        $employee = $this->query()->find($employeeId);

        return $employee === null ? null : $this->snapshot($employee);
    }

    public function findMany(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $result = [];

        foreach ($this->query()->whereKey(array_values(array_unique($employeeIds)))->get() as $employee) {
            $result[$employee->id] = $this->snapshot($employee);
        }

        return $result;
    }

    public function findByMembership(string $membershipId): ?EmployeeSnapshot
    {
        $employee = $this->query()->where('owner_id', $membershipId)->first();

        return $employee === null ? null : $this->snapshot($employee);
    }

    public function list(array $filters = [], int $limit = 100, ?string $afterId = null): array
    {
        $query = $this->query()->orderBy('id')->limit(max(1, min(500, $limit)));

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        if (isset($filters['status']) && $filters['status'] !== []) {
            $query->whereIn('status', $filters['status']);
        }

        foreach (['department_id', 'branch_id', 'manager_id', 'employment_type'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        if (isset($filters['active_on'])) {
            $date = $filters['active_on'];
            $query->where('joined_on', '<=', $date)
                ->where(fn ($inner) => $inner->whereNull('terminated_on')->orWhere('terminated_on', '>=', $date));
        }

        return $query->get()->map(fn (Employee $employee): EmployeeSnapshot => $this->snapshot($employee))->all();
    }

    public function directReports(string $employeeId): array
    {
        return $this->query()->where('manager_id', $employeeId)->orderBy('first_name')->get()
            ->map(fn (Employee $employee): EmployeeSnapshot => $this->snapshot($employee))->all();
    }

    public function managerChain(string $employeeId, int $depth = 10): array
    {
        $chain = [];
        $seen = [$employeeId => true];
        $cursor = Employee::query()->whereKey($employeeId)->value('manager_id');

        while ($cursor !== null && count($chain) < max(1, min(50, $depth)) && ! isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $manager = $this->query()->find($cursor);

            if ($manager === null) {
                break;
            }

            $chain[] = $this->snapshot($manager);
            $cursor = $manager->manager_id;
        }

        return $chain;
    }

    public function attendance(string $employeeId, string $from, string $to): array
    {
        $this->guardRange($from, $to);

        $days = AttendanceDay::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$from, $to])
            ->get();

        $closed = $days->where('is_closed', true);
        $count = static fn (DayStatus $status): int => $closed->where('status', $status)->count();

        return [
            'from' => $from,
            'to' => $to,
            'days_present' => $count(DayStatus::Present),
            'days_half' => $count(DayStatus::HalfDay),
            'days_absent' => $count(DayStatus::Absent),
            'days_on_leave' => $count(DayStatus::OnLeave),
            'days_holiday' => $count(DayStatus::Holiday),
            'days_weekly_off' => $count(DayStatus::WeeklyOff),
            'days_incomplete' => $count(DayStatus::Incomplete),
            'open_days' => $days->where('is_closed', false)->count(),
            'scheduled_minutes' => (int) $closed->sum('scheduled_minutes'),
            'worked_minutes' => (int) $closed->sum('worked_minutes'),
            'late_minutes' => (int) $closed->sum('late_minutes'),
            'early_leave_minutes' => (int) $closed->sum('early_leave_minutes'),
            'overtime_minutes' => (int) $closed->sum('overtime_minutes'),
        ];
    }

    public function leaveUsage(string $employeeId, string $from, string $to): array
    {
        $this->guardRange($from, $to);

        $requests = LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employeeId)
            ->where('status', LeaveStatus::Approved)
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->get();

        $totals = [];

        foreach ($requests as $request) {
            $units = 0;

            foreach ($request->days as $date => $value) {
                if ($date >= $from && $date <= $to) {
                    $units += (int) $value;
                }
            }

            if ($units === 0) {
                continue;
            }

            $type = $request->leaveType;
            $totals[$type->id] ??= ['leave_type_id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'is_paid' => $type->is_paid, 'units' => 0];
            $totals[$type->id]['units'] += $units;
        }

        ksort($totals);

        return array_values($totals);
    }

    /** @return Builder<Employee> */
    private function query(): Builder
    {
        return Employee::query()->with(['department:id,name', 'designation:id,name']);
    }

    private function snapshot(Employee $employee): EmployeeSnapshot
    {
        return new EmployeeSnapshot(
            $employee->id,
            $employee->employee_number,
            $employee->first_name,
            $employee->last_name,
            $employee->fullName(),
            $employee->owner_id,
            $employee->work_email,
            $employee->department_id,
            $employee->department?->name,
            $employee->branch_id,
            $employee->designation_id,
            $employee->designation?->name,
            $employee->employment_type->value,
            $employee->status->value,
            $employee->manager_id,
            $employee->joined_on->toDateString(),
            $employee->terminated_on?->toDateString(),
            $employee->last_working_on?->toDateString(),
            $employee->cost_rate_amount,
            $employee->cost_currency,
            $employee->probation_ends_on?->toDateString(),
            $employee->confirmed_on?->toDateString(),
        );
    }

    private function guardRange(string $from, string $to): void
    {
        if ($to < $from || CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')) > 365) {
            throw new InvalidArgumentException('The period must be between 1 and 366 days.');
        }
    }
}
