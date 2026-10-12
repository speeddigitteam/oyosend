<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Enums\LedgerKind;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/** Aggregate HR reports: every figure is a SQL aggregate over integer columns. */
final class HrmReports
{
    /**
     * Headcount of everyone who is not terminated, grouped, plus joiners and leavers in a period.
     *
     * @return array{
     *     total: int, by_status: array<string, int>, by_employment_type: array<string, int>,
     *     by_department: list<array{id: string|null, name: string|null, count: int}>,
     *     by_branch: list<array{id: string|null, name: string|null, count: int}>,
     *     by_designation: list<array{id: string|null, name: string|null, count: int}>,
     *     joined: int, left: int, from: string, to: string
     * }
     */
    public function headcount(string $from, string $to): array
    {
        $current = Employee::query()->where('status', '!=', EmployeeStatus::Terminated);

        $byStatus = Employee::query()->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)->all();
        $byType = (clone $current)->groupBy('employment_type')->selectRaw('employment_type, COUNT(*) as total')->pluck('total', 'employment_type')
            ->map(static fn (mixed $total): int => (int) $total)->all();

        return [
            'total' => (int) (clone $current)->count(),
            'by_status' => $byStatus,
            'by_employment_type' => $byType,
            'by_department' => $this->grouped(clone $current, 'department_id', Department::class),
            'by_branch' => $this->grouped(clone $current, 'branch_id', Branch::class),
            'by_designation' => $this->grouped(clone $current, 'designation_id', Designation::class),
            'joined' => Employee::query()->whereBetween('joined_on', [$from, $to])->count(),
            'left' => Employee::query()->whereBetween('terminated_on', [$from, $to])->count(),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Per-employee attendance totals over closed days, from a query the caller has already
     * narrowed to the records the viewer may see.
     *
     * @param  Builder<AttendanceDay>  $days
     * @return LengthAwarePaginator<int, mixed>
     */
    public function attendanceByEmployee(Builder $days, string $from, string $to, int $perPage = 25): LengthAwarePaginator
    {
        $statuses = fn (DayStatus $status): string => "SUM(CASE WHEN status = '{$status->value}' THEN 1 ELSE 0 END)";

        $page = $days->where('is_closed', true)
            ->whereBetween('work_date', [$from, $to])
            ->groupBy('employee_id')
            ->selectRaw(implode(', ', [
                'employee_id',
                $statuses(DayStatus::Present).' as present',
                $statuses(DayStatus::HalfDay).' as half_day',
                $statuses(DayStatus::Absent).' as absent',
                $statuses(DayStatus::OnLeave).' as on_leave',
                $statuses(DayStatus::Incomplete).' as incomplete',
                'SUM(scheduled_minutes) as scheduled_minutes',
                'SUM(worked_minutes) as worked_minutes',
                'SUM(late_minutes) as late_minutes',
                'SUM(early_leave_minutes) as early_leave_minutes',
                'SUM(overtime_minutes) as overtime_minutes',
                'SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days',
            ]))
            ->orderBy('employee_id')
            ->paginate($perPage);

        $employees = Employee::query()->whereKey($page->pluck('employee_id')->all())->get(['id', 'employee_number', 'first_name', 'last_name'])->keyBy('id');

        return $page->through(static function (AttendanceDay $row) use ($employees): array {
            $employee = $employees->get($row->employee_id);

            return [
                'employeeId' => $row->employee_id,
                'employeeNumber' => $employee?->employee_number,
                'name' => $employee?->fullName(),
                'present' => (int) $row->getAttribute('present'),
                'halfDay' => (int) $row->getAttribute('half_day'),
                'absent' => (int) $row->getAttribute('absent'),
                'onLeave' => (int) $row->getAttribute('on_leave'),
                'incomplete' => (int) $row->getAttribute('incomplete'),
                'lateDays' => (int) $row->getAttribute('late_days'),
                'scheduledMinutes' => (int) $row->getAttribute('scheduled_minutes'),
                'workedMinutes' => (int) $row->getAttribute('worked_minutes'),
                'lateMinutes' => (int) $row->getAttribute('late_minutes'),
                'earlyLeaveMinutes' => (int) $row->getAttribute('early_leave_minutes'),
                'overtimeMinutes' => (int) $row->getAttribute('overtime_minutes'),
            ];
        });
    }

    /**
     * Leave balances and usage per leave type: what was granted, taken (approved and pending) and
     * what is left, for one leave year, plus approved usage per department in the period.
     *
     * @return array{
     *     year: int,
     *     by_type: list<array{leave_type_id: string, name: string, code: string, granted: int, used: int, pending: int, balance: int}>,
     *     by_department: list<array{department_id: string|null, name: string|null, units: int}>
     * }
     */
    public function leave(int $year, string $from, string $to): array
    {
        $rows = LeaveLedgerEntry::query()
            ->where('leave_year', $year)
            ->groupBy('leave_type_id')
            ->selectRaw('leave_type_id, SUM(units) as balance')
            ->selectRaw("SUM(CASE WHEN kind IN ('".LedgerKind::Accrual->value."','".LedgerKind::CarryForward->value."') OR (kind = '".LedgerKind::Adjustment->value."' AND units > 0) THEN units ELSE 0 END) as granted")
            ->get()
            ->keyBy('leave_type_id');

        $usage = LeaveRequest::query()
            ->where('leave_year', $year)
            ->whereIn('status', [LeaveStatus::Approved, LeaveStatus::PendingManager, LeaveStatus::PendingHr])
            ->groupBy('leave_type_id', 'status')
            ->selectRaw('leave_type_id, status, SUM(units) as total')
            ->get();

        $types = LeaveType::query()->orderBy('sort_order')->orderBy('name')->get();

        $byType = $types->map(function (LeaveType $type) use ($rows, $usage): array {
            $row = $rows->get($type->id);
            $approved = (int) $usage->where('leave_type_id', $type->id)->where('status', LeaveStatus::Approved)->sum('total');
            $pending = (int) $usage->where('leave_type_id', $type->id)->filter(fn ($item): bool => $item->status->isPending())->sum('total');

            return [
                'leave_type_id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'granted' => (int) ($row?->getAttribute('granted') ?? 0),
                'used' => $approved,
                'pending' => $pending,
                'balance' => (int) ($row?->getAttribute('balance') ?? 0),
            ];
        })->values()->all();

        $departments = LeaveRequest::query()
            ->where('status', LeaveStatus::Approved)
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->groupBy('department_id')
            ->selectRaw('department_id, SUM(units) as total')
            ->pluck('total', 'department_id');
        $names = Department::query()->whereKey($departments->keys()->filter()->all())->pluck('name', 'id');

        return [
            'year' => $year,
            'by_type' => $byType,
            'by_department' => $departments->map(static fn (mixed $total, mixed $id): array => [
                'department_id' => $id === '' ? null : (string) $id,
                'name' => $names->get($id),
                'units' => (int) $total,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  class-string<Model>  $model
     * @return list<array{id: string|null, name: string|null, count: int}>
     */
    private function grouped(Builder $query, string $column, string $model): array
    {
        $counts = $query->groupBy($column)->selectRaw("{$column} as group_id, COUNT(*) as total")->pluck('total', 'group_id');
        $names = $model::query()->whereKey($counts->keys()->filter()->all())->pluck('name', 'id');

        return $counts->map(static fn (mixed $total, mixed $id): array => [
            'id' => $id === '' ? null : (string) $id,
            'name' => $names->get($id),
            'count' => (int) $total,
        ])->sortByDesc('count')->values()->all();
    }
}
