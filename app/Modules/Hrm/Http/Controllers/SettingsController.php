<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Hrm\Actions\HrmCatalogue;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Actions\ShiftManager;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\LeaveAccrual;
use App\Modules\Hrm\Enums\LeaveApprovalFlow;
use App\Modules\Hrm\Http\Presenters\HrmPresenter;
use App\Modules\Hrm\Http\Requests\DesignationRequest;
use App\Modules\Hrm\Http\Requests\LeavePolicyRequest;
use App\Modules\Hrm\Http\Requests\LeaveTypeRequest;
use App\Modules\Hrm\Http\Requests\SettingsRequest;
use App\Modules\Hrm\Http\Requests\ShiftRequest;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Workspace HR configuration: numbering, calendar, designations, shifts, leave types and policies. */
final class SettingsController
{
    public function __construct(
        private readonly HrmSettings $settings,
        private readonly HrmCatalogue $catalogue,
        private readonly ShiftManager $shifts,
    ) {}

    public function index(Request $request): Response
    {
        $settings = $this->settings->current();

        return Inertia::render('Modules/Hrm/Pages/Settings/Index', [
            'settings' => [
                'employeePrefix' => $settings->employee_prefix,
                'employeePadding' => $settings->employee_padding,
                'weeklyOffDays' => $settings->weekly_off_days,
                'leaveYearStartMonth' => $settings->leave_year_start_month,
                'workDayMinutes' => $settings->work_day_minutes,
            ],
            'designations' => Designation::query()->orderBy('name')->get()->map(static fn (Designation $designation): array => HrmPresenter::designation($designation))->all(),
            'shifts' => Shift::query()->orderBy('name')->get()->map(static fn (Shift $shift): array => HrmPresenter::shift($shift))->all(),
            'leaveTypes' => LeaveType::query()->orderBy('sort_order')->orderBy('name')->get()->map(static fn (LeaveType $type): array => HrmPresenter::leaveType($type))->all(),
            'leavePolicies' => LeavePolicy::query()->orderBy('name')->get()->map(static fn (LeavePolicy $policy): array => HrmPresenter::leavePolicy($policy))->all(),
            'options' => [
                'employmentTypes' => array_map(static fn (EmploymentType $type): string => $type->value, EmploymentType::cases()),
                'accruals' => array_map(static fn (LeaveAccrual $accrual): string => $accrual->value, LeaveAccrual::cases()),
                'approvalFlows' => array_map(static fn (LeaveApprovalFlow $flow): string => $flow->value, LeaveApprovalFlow::cases()),
            ],
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $input = $request->validated();
        $input['weekly_off_days'] = array_values(array_map('intval', $input['weekly_off_days']));
        sort($input['weekly_off_days']);
        $this->settings->update($input);
        Toast::success(__('HR settings saved.'));

        return back();
    }

    public function storeDesignation(DesignationRequest $request): RedirectResponse
    {
        $this->catalogue->saveDesignation(null, $request->validated());
        Toast::success(__('Designation added.'));

        return back();
    }

    public function updateDesignation(DesignationRequest $request, Designation $designation): RedirectResponse
    {
        $this->catalogue->saveDesignation($designation, $request->validated());
        Toast::success(__('Designation saved.'));

        return back();
    }

    public function storeShift(ShiftRequest $request): RedirectResponse
    {
        $this->shifts->save(null, $this->shiftInput($request->validated()));
        Toast::success(__('Shift added.'));

        return back();
    }

    public function updateShift(ShiftRequest $request, Shift $shift): RedirectResponse
    {
        $this->shifts->save($shift, $this->shiftInput($request->validated()));
        Toast::success(__('Shift saved.'));

        return back();
    }

    public function storeLeaveType(LeaveTypeRequest $request): RedirectResponse
    {
        $this->catalogue->saveLeaveType(null, $this->typeInput($request->validated()));
        Toast::success(__('Leave type added.'));

        return back();
    }

    public function updateLeaveType(LeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $this->catalogue->saveLeaveType($leaveType, $this->typeInput($request->validated()));
        Toast::success(__('Leave type saved.'));

        return back();
    }

    public function storeLeavePolicy(LeavePolicyRequest $request): RedirectResponse
    {
        $this->catalogue->saveLeavePolicy(null, $this->policyInput($request->validated()));
        Toast::success(__('Leave policy added.'));

        return back();
    }

    public function updateLeavePolicy(LeavePolicyRequest $request, LeavePolicy $policy): RedirectResponse
    {
        $this->catalogue->saveLeavePolicy($policy, $this->policyInput($request->validated()));
        Toast::success(__('Leave policy saved.'));

        return back();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function shiftInput(array $input): array
    {
        if (isset($input['weekly_off_days'])) {
            $days = array_values(array_map('intval', $input['weekly_off_days']));
            sort($days);
            $input['weekly_off_days'] = $days;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function typeInput(array $input): array
    {
        $input['code'] = strtoupper((string) $input['code']);
        $input['sort_order'] = (int) ($input['sort_order'] ?? 0);

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function policyInput(array $input): array
    {
        $input['employment_types'] = ($input['employment_types'] ?? []) === [] ? null : array_values($input['employment_types']);

        return $input;
    }
}
