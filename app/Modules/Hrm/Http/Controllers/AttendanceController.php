<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Hrm\Actions\AttendanceManager;
use App\Modules\Hrm\Actions\EmployeeResolver;
use App\Modules\Hrm\Actions\HrmReports;
use App\Modules\Hrm\Actions\LeaveDays;
use App\Modules\Hrm\Actions\ShiftManager;
use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Http\Presenters\HrmPresenter;
use App\Modules\Hrm\Http\Requests\ClockRequest;
use App\Modules\Hrm\Http\Requests\ManualEntryRequest;
use App\Modules\Hrm\Http\Requests\ShiftAssignmentRequest;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShiftAssignment;
use App\Modules\Hrm\Support\HrmPermissions;
use App\Modules\Hrm\Support\WorkCalendars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AttendanceController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly EmployeeResolver $resolver,
        private readonly AttendanceManager $attendance,
        private readonly ShiftManager $shifts,
        private readonly HrmReports $reports,
        private readonly WorkCalendars $calendars,
        private readonly LeaveDays $leaveDays,
        private readonly HrmControllerSupport $support,
    ) {}

    /** One day for everyone the person may see: who is in, who is late, who is absent. */
    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $today = $this->support->today()->toDateString();
        $date = $this->support->date($request, 'date', $today);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        $scoped = $this->records->constrain(Employee::query(), $actor, HrmPermissions::ATTENDANCE_VIEW)
            ->whereIn('status', [EmployeeStatus::Probation, EmployeeStatus::Active])
            ->where('joined_on', '<=', $date);

        if (($filters['department_id'] ?? '') !== '') {
            $scoped->where('department_id', $filters['department_id']);
        }

        if (($filters['q'] ?? '') !== '') {
            $like = Like::contains($filters['q']);
            $scoped->where(fn ($match) => $match->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('employee_number', 'like', $like));
        }

        if (($filters['status'] ?? '') === 'not_recorded') {
            $scoped->whereDoesntHave('attendanceDays', fn ($days) => $days->where('work_date', $date));
        } elseif (($filters['status'] ?? '') !== '') {
            $scoped->whereHas('attendanceDays', fn ($days) => $days->where('work_date', $date)->where('status', $filters['status']));
        }

        $page = $scoped
            ->with(['department:id,name', 'attendanceDays' => fn ($days) => $days->where('work_date', $date)])
            ->orderBy('employee_number')
            ->paginate(30)
            ->withQueryString();

        $open = AttendanceEntry::query()->whereIn('employee_id', $page->pluck('id')->all())->whereNull('clock_out_at')->pluck('employee_id')->flip();

        $rows = $page->through(static function (Employee $employee) use ($open): array {
            /** @var AttendanceDay|null $day */
            $day = $employee->attendanceDays->first();

            return [
                'employee' => ['id' => $employee->id, 'number' => $employee->employee_number, 'name' => $employee->fullName(), 'department' => $employee->department?->name],
                'day' => $day === null ? null : HrmPresenter::day($day),
                'clockedIn' => $open->has($employee->id),
            ];
        });

        $totals = $this->records->constrain(AttendanceDay::query(), $actor, HrmPermissions::ATTENDANCE_VIEW)
            ->where('work_date', $date)->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)->all();

        return Inertia::render('Modules/Hrm/Pages/Attendance/Index', [
            'date' => $date,
            'today' => $today,
            'rows' => $rows,
            'totals' => $totals,
            'statuses' => [...array_map(static fn (DayStatus $status): string => $status->value, DayStatus::cases()), 'not_recorded'],
            'filters' => ['q' => $filters['q'] ?? '', 'department_id' => $filters['department_id'] ?? '', 'status' => $filters['status'] ?? ''],
            'departments' => $this->support->employeeFormOptions()['departments'],
            'abilities' => [
                'manage' => $this->access->allows($actor, HrmPermissions::ATTENDANCE_MANAGE),
                'clock' => $this->access->allows($actor, HrmPermissions::ATTENDANCE_CLOCK),
            ],
        ]);
    }

    /** One employee's month: days, entries, shift assignments and the calendar around them. */
    public function employee(Request $request, Employee $employee): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::ATTENDANCE_VIEW, $employee);
        $month = $this->support->month($request);
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $canManage = $this->records->allows($actor, HrmPermissions::ATTENDANCE_MANAGE, $employee)
            && (! $this->resolver->isSelf($actor, $employee) || $this->access->scopeFor($actor, HrmPermissions::ATTENDANCE_MANAGE) === AccessScope::All);

        $days = AttendanceDay::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get();
        $calendar = $this->calendars->forEmployee($employee->id, $from, $to);
        $leave = $this->leaveDays->approved($employee->id, $from, $to);

        $totals = [
            'present' => $days->where('status', DayStatus::Present)->count(),
            'halfDay' => $days->where('status', DayStatus::HalfDay)->count(),
            'absent' => $days->where('status', DayStatus::Absent)->count(),
            'onLeave' => $days->where('status', DayStatus::OnLeave)->count(),
            'workedMinutes' => (int) $days->sum('worked_minutes'),
            'lateMinutes' => (int) $days->sum('late_minutes'),
            'earlyLeaveMinutes' => (int) $days->sum('early_leave_minutes'),
            'overtimeMinutes' => (int) $days->sum('overtime_minutes'),
        ];

        return Inertia::render('Modules/Hrm/Pages/Attendance/Employee', [
            'employee' => ['id' => $employee->id, 'number' => $employee->employee_number, 'name' => $employee->fullName(), 'status' => $employee->status->value],
            'month' => $month->format('Y-m'),
            'days' => $days->map(static fn (AttendanceDay $day): array => HrmPresenter::day($day))->all(),
            'entries' => AttendanceEntry::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])
                ->orderBy('clock_in_at')->get()->map(static fn (AttendanceEntry $entry): array => HrmPresenter::entry($entry))->all(),
            'holidays' => $this->calendars->holidays($from, $to),
            'leave' => $leave,
            'weeklyOff' => collect(range(0, (int) $month->endOfMonth()->format('j') - 1))
                ->map(static fn (int $offset): string => $month->addDays($offset)->toDateString())
                ->filter(fn (string $date): bool => $calendar->isWeeklyOff($date))->values()->all(),
            'assignments' => ShiftAssignment::query()->with('shift:id,name')->where('employee_id', $employee->id)
                ->orderByDesc('starts_on')->limit(20)->get()->map(static fn (ShiftAssignment $assignment): array => HrmPresenter::shiftAssignment($assignment))->all(),
            'totals' => $totals,
            'shifts' => $canManage ? Shift::query()->where('is_active', true)->orderBy('name')->get()->map(static fn (Shift $shift): array => HrmPresenter::shift($shift))->all() : [],
            'abilities' => ['manage' => $canManage],
        ]);
    }

    /** Per-employee totals for a month, for everyone the person may see. */
    public function summary(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $month = $this->support->month($request);
        $department = $request->validate(['department_id' => ['nullable', 'string', 'size:26']])['department_id'] ?? null;

        $days = $this->records->constrain(AttendanceDay::query(), $actor, HrmPermissions::ATTENDANCE_VIEW);

        if ($department !== null && $department !== '') {
            $days->where('department_id', $department);
        }

        return Inertia::render('Modules/Hrm/Pages/Attendance/Summary', [
            'month' => $month->format('Y-m'),
            'from' => $month->toDateString(),
            'to' => $month->endOfMonth()->toDateString(),
            'rows' => $this->reports->attendanceByEmployee($days, $month->toDateString(), $month->endOfMonth()->toDateString())->withQueryString(),
            'filters' => ['department_id' => $department ?? ''],
            'departments' => $this->support->employeeFormOptions()['departments'],
        ]);
    }

    public function clockIn(ClockRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->attendance->clockIn($this->ownEmployee($actor), $request->validated('note'), $this->support->membership($actor));
        Toast::success(__('You are clocked in.'));

        return back();
    }

    public function clockOut(ClockRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->attendance->clockOut($this->ownEmployee($actor), $this->support->membership($actor));
        Toast::success(__('You are clocked out.'));

        return back();
    }

    public function storeEntry(ManualEntryRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $this->authorizeManage($actor, $employee);
        $this->attendance->record($employee, $input['clock_in_at'], $input['clock_out_at'], $input['note'] ?? null, $this->support->membership($actor));
        Toast::success(__('Attendance recorded.'));

        return back();
    }

    public function destroyEntry(Request $request, AttendanceEntry $entry): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $employee = Employee::query()->findOrFail($entry->employee_id);
        $this->authorizeManage($actor, $employee);
        $this->attendance->deleteEntry($entry, $this->support->membership($actor));
        Toast::success(__('Attendance entry removed.'));

        return back();
    }

    public function assignShift(ShiftAssignmentRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $this->records->authorize($actor, HrmPermissions::ATTENDANCE_MANAGE, $employee);
        $shift = Shift::query()->findOrFail($input['shift_id']);
        $this->shifts->assign($employee, $shift, $input['starts_on'], $input['ends_on'] ?? null, $this->support->membership($actor));
        Toast::success(__('Shift assigned.'));

        return back();
    }

    private function ownEmployee(User $actor): Employee
    {
        $employee = $this->support->ownEmployee($actor);

        if ($employee === null) {
            throw ValidationException::withMessages(['clock' => __('Your account is not linked to an employee record.')]);
        }

        return $employee;
    }

    /** HR changes attendance of others; nobody edits their own unless they hold the permission for everyone. */
    private function authorizeManage(User $actor, Employee $employee): void
    {
        $this->records->authorize($actor, HrmPermissions::ATTENDANCE_MANAGE, $employee);
        abort_if(
            $this->resolver->isSelf($actor, $employee) && $this->access->scopeFor($actor, HrmPermissions::ATTENDANCE_MANAGE) !== AccessScope::All,
            403,
        );
    }
}
