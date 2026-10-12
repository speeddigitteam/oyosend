<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Actions\LeaveBalances;
use App\Modules\Hrm\Actions\LeaveManager;
use App\Modules\Hrm\Enums\LeaveSession;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Http\Presenters\HrmPresenter;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Support\HrmPermissions;
use App\Modules\Hrm\Support\WorkCalendars;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Employee self-service: the signed-in person's own HR picture, built only from their own records. */
final class MeController
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly HrmSettings $settings,
        private readonly LeaveBalances $balances,
        private readonly LeaveManager $leave,
        private readonly WorkCalendars $calendars,
        private readonly HrmControllerSupport $support,
    ) {}

    public function show(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $employee = $this->support->ownEmployee($actor);

        if ($employee === null) {
            return Inertia::render('Modules/Hrm/Pages/Me/Show', ['hasProfile' => false, 'employee' => null]);
        }

        $employee->load(['department:id,name', 'branch:id,name', 'designation:id,name', 'manager:id,first_name,last_name']);
        $today = $this->support->today();
        $month = $this->support->month($request);
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $year = $this->settings->leaveYear($today->toDateString());
        $balances = $this->balances->forEmployee($employee->id, $year);
        $calendar = $this->calendars->forEmployee($employee->id, $today->toDateString(), $today->toDateString());
        $shift = $calendar->shiftOn($today->toDateString());

        $types = LeaveType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        $requests = LeaveRequest::query()->with(['employee', 'leaveType'])->where('employee_id', $employee->id)
            ->orderByDesc('starts_on')->limit(20)->get()
            ->map(fn (LeaveRequest $item): array => [
                ...HrmPresenter::leaveRequest($item),
                'abilities' => ['cancel' => $this->leave->mayCancel($actor, $item)],
            ])->all();

        $open = AttendanceEntry::query()->where('employee_id', $employee->id)->whereNull('clock_out_at')->first();
        $todayDay = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $today->toDateString())->first();

        return Inertia::render('Modules/Hrm/Pages/Me/Show', [
            'hasProfile' => true,
            'employee' => [
                ...HrmPresenter::employeeRow($employee),
                'confirmedOn' => $employee->confirmed_on?->toDateString(),
                'probationEndsOn' => $employee->probation_ends_on?->toDateString(),
            ],
            'today' => $today->toDateString(),
            'clock' => [
                'clockedIn' => $open !== null,
                'since' => $open?->clock_in_at->toIso8601String(),
                'day' => $todayDay === null ? null : HrmPresenter::day($todayDay),
                'shift' => $shift === null ? null : HrmPresenter::shift($shift),
                'isWorkingDay' => $calendar->isWorkingDay($today->toDateString()),
                'holiday' => $calendar->holidayName($today->toDateString()),
            ],
            'month' => $month->format('Y-m'),
            'days' => AttendanceDay::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get()
                ->map(static fn (AttendanceDay $day): array => HrmPresenter::day($day))->all(),
            'leaveYear' => $year,
            'balances' => $types->where('tracks_balance', true)->map(static fn (LeaveType $type): array => [
                'leaveType' => HrmPresenter::leaveType($type),
                'units' => $balances[$type->id] ?? 0,
            ])->values()->all(),
            'leaveTypes' => $types->map(static fn (LeaveType $type): array => HrmPresenter::leaveType($type))->values()->all(),
            'sessions' => array_map(static fn (LeaveSession $session): string => $session->value, LeaveSession::cases()),
            'requests' => $requests,
            'corrections' => Regularization::query()->with('employee')->where('employee_id', $employee->id)
                ->where('status', RegularizationStatus::Pending)->orderByDesc('work_date')->limit(10)->get()
                ->map(static fn (Regularization $item): array => HrmPresenter::correction($item))->all(),
            'upcomingHolidays' => Holiday::query()->where('holiday_on', '>=', $today->toDateString())->orderBy('holiday_on')->limit(5)->get()
                ->map(static fn (Holiday $holiday): array => HrmPresenter::holiday($holiday))->all(),
            'abilities' => [
                'clock' => $employee->status->isWorking() && $this->access->allows($actor, HrmPermissions::ATTENDANCE_CLOCK),
                'requestLeave' => $employee->status->isWorking() && $this->access->allows($actor, HrmPermissions::LEAVE_REQUEST),
                'requestCorrection' => $this->access->allows($actor, HrmPermissions::ATTENDANCE_REGULARIZE),
                'viewLeave' => $this->access->allows($actor, HrmPermissions::LEAVE_VIEW),
                'viewAttendance' => $this->access->allows($actor, HrmPermissions::ATTENDANCE_VIEW),
            ],
        ]);
    }
}
