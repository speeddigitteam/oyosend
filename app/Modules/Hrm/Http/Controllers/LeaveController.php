<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Hrm\Actions\EmployeeResolver;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Actions\LeaveAdjustments;
use App\Modules\Hrm\Actions\LeaveBalances;
use App\Modules\Hrm\Actions\LeaveManager;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\LeaveSession;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Http\Presenters\HrmPresenter;
use App\Modules\Hrm\Http\Requests\AdjustmentRequest;
use App\Modules\Hrm\Http\Requests\CancelRequest;
use App\Modules\Hrm\Http\Requests\DecisionRequest;
use App\Modules\Hrm\Http\Requests\LeaveSubmitRequest;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Support\HrmPermissions;
use App\Modules\Hrm\Support\WorkCalendars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LeaveController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly EmployeeResolver $resolver,
        private readonly LeaveManager $leave,
        private readonly LeaveAdjustments $adjustments,
        private readonly LeaveBalances $balances,
        private readonly HrmSettings $settings,
        private readonly WorkCalendars $calendars,
        private readonly HrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,pending_manager,pending_hr,approved,rejected,cancelled,all'],
            'employee_id' => ['nullable', 'string', 'size:26'],
            'leave_type_id' => ['nullable', 'string', 'size:26'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $query = $this->records->constrain(LeaveRequest::query(), $actor, HrmPermissions::LEAVE_VIEW)->with(['employee', 'leaveType']);
        $status = $filters['status'] ?? 'all';

        if ($status === 'pending') {
            $query->whereIn('status', [LeaveStatus::PendingManager, LeaveStatus::PendingHr]);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        foreach (['employee_id', 'leave_type_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['year'])) {
            $query->where('leave_year', $filters['year']);
        }

        $page = $query->orderByDesc('starts_on')->orderByDesc('created_at')->paginate(25)->withQueryString()
            ->through(fn (LeaveRequest $request): array => $this->present($request, $actor));

        $own = $this->support->ownEmployee($actor);

        return Inertia::render('Modules/Hrm/Pages/Leave/Index', [
            'requests' => $page,
            'filters' => ['status' => $status, 'employee_id' => $filters['employee_id'] ?? '', 'leave_type_id' => $filters['leave_type_id'] ?? '', 'year' => $filters['year'] ?? null],
            'types' => $this->typeOptions(),
            'abilities' => [
                'request' => $own !== null && $this->access->allows($actor, HrmPermissions::LEAVE_REQUEST),
                'manage' => $this->access->allows($actor, HrmPermissions::LEAVE_MANAGE),
                'approve' => $this->access->allows($actor, HrmPermissions::LEAVE_APPROVE),
            ],
            'sessions' => array_map(static fn (LeaveSession $session): string => $session->value, LeaveSession::cases()),
            'today' => $this->support->today()->toDateString(),
        ]);
    }

    /** The approver's inbox: their team's requests waiting for the manager step and the HR-step requests in scope. */
    public function approvals(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $own = $this->support->ownEmployee($actor);
        $reportIds = $own === null ? [] : Employee::query()->where('manager_id', $own->id)->pluck('id')->all();
        $hr = $this->records->constrain(LeaveRequest::query(), $actor, HrmPermissions::LEAVE_FINALIZE)->select('id');

        $page = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->whereIn('status', [LeaveStatus::PendingManager, LeaveStatus::PendingHr])
            ->when($own !== null, fn ($query) => $query->where('employee_id', '!=', $own?->id))
            ->where(function ($match) use ($reportIds, $hr): void {
                $match->whereIn('id', $hr);

                if ($reportIds !== []) {
                    $match->orWhere(fn ($team) => $team->where('status', LeaveStatus::PendingManager)->whereIn('employee_id', $reportIds));
                }
            })
            ->orderBy('starts_on')
            ->paginate(25)
            ->through(fn (LeaveRequest $request): array => $this->present($request, $actor));

        return Inertia::render('Modules/Hrm/Pages/Leave/Approvals', ['requests' => $page]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::LEAVE_VIEW, $leaveRequest);
        $leaveRequest->load(['employee', 'leaveType', 'approvals']);
        $balance = $leaveRequest->leaveType->tracks_balance
            ? $this->balances->balance($leaveRequest->employee_id, $leaveRequest->leave_type_id, $leaveRequest->leave_year)
            : null;

        return Inertia::render('Modules/Hrm/Pages/Leave/Show', [
            'request' => [...$this->present($leaveRequest, $actor), 'approvals' => HrmPresenter::leaveRequest($leaveRequest, true)['approvals']],
            'balanceUnits' => $balance,
        ]);
    }

    public function store(LeaveSubmitRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();
        $own = $this->support->ownEmployee($actor);
        $employee = $own;
        $onBehalf = ($input['employee_id'] ?? null) !== null && $input['employee_id'] !== $own?->id;

        if ($onBehalf) {
            $employee = Employee::query()->findOrFail($input['employee_id']);
            $this->records->authorize($actor, HrmPermissions::LEAVE_MANAGE, $employee);
        } elseif (! $this->access->allows($actor, HrmPermissions::LEAVE_REQUEST)) {
            abort(403);
        }

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => __('Your account is not linked to an employee record.')]);
        }

        $leave = $this->leave->submit($employee, $input, $this->support->membership($actor));
        Toast::success(__('Leave requested.'));

        return redirect()->route('hrm.leave.requests.show', $leave);
    }

    public function decision(DecisionRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();
        $this->leave->decide($leaveRequest, $actor, $input['decision'] === 'approve', $input['note'] ?? null, $this->support->membership($actor));
        Toast::success($input['decision'] === 'approve' ? __('Leave request approved.') : __('Leave request rejected.'));

        return back();
    }

    public function cancel(CancelRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $leaveRequest->load('employee');

        // A request nobody may see is not disclosed (404): own, in scope, or something the person manages.
        abort_unless(
            $this->resolver->isSelf($actor, $leaveRequest->employee)
                || $this->records->allows($actor, HrmPermissions::LEAVE_VIEW, $leaveRequest)
                || $this->records->allows($actor, HrmPermissions::LEAVE_MANAGE, $leaveRequest),
            404,
        );

        $this->leave->cancel($leaveRequest, $actor, $request->validated('reason'), $this->support->membership($actor));
        Toast::success(__('Leave request cancelled.'));

        return back();
    }

    /** Balances per employee and leave type for a leave year. */
    public function balances(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year']
            ?? $this->settings->leaveYear($this->support->today()->toDateString()));

        $employees = $this->records->constrain(Employee::query(), $actor, HrmPermissions::LEAVE_VIEW)
            ->where('status', '!=', EmployeeStatus::Terminated)
            ->orderBy('employee_number')
            ->paginate(25)
            ->withQueryString();

        $sums = LeaveLedgerEntry::query()
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->where('leave_year', $year)
            ->groupBy('employee_id', 'leave_type_id')
            ->selectRaw('employee_id, leave_type_id, SUM(units) as total')
            ->get();

        $rows = $employees->through(static function (Employee $employee) use ($sums): array {
            $own = $sums->where('employee_id', $employee->id);

            return [
                'employee' => ['id' => $employee->id, 'number' => $employee->employee_number, 'name' => $employee->fullName()],
                'balances' => $own->mapWithKeys(static fn ($row): array => [$row->leave_type_id => (int) $row->getAttribute('total')])->all(),
            ];
        });

        return Inertia::render('Modules/Hrm/Pages/Leave/Balances', [
            'year' => $year,
            'rows' => $rows,
            'types' => LeaveType::query()->where('tracks_balance', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(static fn (LeaveType $type): array => HrmPresenter::leaveType($type))->all(),
            'canAdjust' => $this->access->allows($actor, HrmPermissions::LEAVE_MANAGE),
        ]);
    }

    public function adjust(AdjustmentRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $this->records->authorize($actor, HrmPermissions::LEAVE_MANAGE, $employee);
        abort_if(
            $this->resolver->isSelf($actor, $employee) && $this->access->scopeFor($actor, HrmPermissions::LEAVE_MANAGE) !== AccessScope::All,
            403,
        );
        $type = LeaveType::query()->findOrFail($input['leave_type_id']);
        $this->adjustments->adjust($employee, $type, (int) $input['units'], $input['note'], isset($input['leave_year']) ? (int) $input['leave_year'] : null, null, $this->support->membership($actor));
        Toast::success(__('Leave balance adjusted.'));

        return back();
    }

    /** Who is away in a month: approved and pending leave in scope, plus holidays. */
    public function calendar(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $month = $this->support->month($request);
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();

        $requests = $this->records->constrain(LeaveRequest::query(), $actor, HrmPermissions::LEAVE_VIEW)
            ->with(['employee:id,employee_number,first_name,last_name', 'leaveType:id,name,code,is_paid'])
            ->whereIn('status', [LeaveStatus::Approved, LeaveStatus::PendingManager, LeaveStatus::PendingHr])
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->orderBy('starts_on')
            ->limit(500)
            ->get()
            ->map(static fn (LeaveRequest $request): array => [
                'id' => $request->id,
                'employee' => ['id' => $request->employee_id, 'name' => $request->employee->fullName(), 'number' => $request->employee->employee_number],
                'leaveType' => ['id' => $request->leave_type_id, 'name' => $request->leaveType->name, 'code' => $request->leaveType->code],
                'startsOn' => $request->starts_on->toDateString(),
                'endsOn' => $request->ends_on->toDateString(),
                'units' => $request->units,
                'days' => $request->days,
                'status' => $request->status->value,
            ])->all();

        return Inertia::render('Modules/Hrm/Pages/Leave/Calendar', [
            'month' => $month->format('Y-m'),
            'from' => $from,
            'to' => $to,
            'requests' => $requests,
            'holidays' => $this->calendars->holidays($from, $to),
            'weeklyOffDays' => $this->settings->current()->weekly_off_days,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function typeOptions(): array
    {
        return LeaveType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(static fn (LeaveType $type): array => HrmPresenter::leaveType($type))->all();
    }

    /** @return array<string, mixed> */
    private function present(LeaveRequest $request, User $actor): array
    {
        $step = $request->status->isPending() ? $this->leave->stepFor($actor, $request) : null;

        return [
            ...HrmPresenter::leaveRequest($request),
            'abilities' => [
                'decide' => $step !== null && $this->access->allows($actor, HrmPermissions::LEAVE_APPROVE),
                'step' => $step?->value,
                'cancel' => $this->leave->mayCancel($actor, $request),
            ],
        ];
    }
}
