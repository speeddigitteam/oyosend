<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Widgets;

use App\Core\Identity\Models\User;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Regularization;

/** The HR dashboard widget: workspace-wide counts, shown with `hrm.reports.view`. */
final class HrmOverviewWidget
{
    public function __construct(private readonly HrmSettings $settings) {}

    /** @return array<string, int> */
    public function __invoke(User $user): array
    {
        $today = $this->settings->today()->toDateString();

        return [
            'headcount' => Employee::query()->where('status', '!=', EmployeeStatus::Terminated)->count(),
            'onProbation' => Employee::query()->where('status', EmployeeStatus::Probation)->count(),
            'onLeaveToday' => LeaveRequest::query()->where('status', LeaveStatus::Approved)
                ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->distinct()->count('employee_id'),
            'clockedInNow' => AttendanceEntry::query()->whereNull('clock_out_at')->count(),
            'pendingLeave' => LeaveRequest::query()->whereIn('status', [LeaveStatus::PendingManager, LeaveStatus::PendingHr])->count(),
            'pendingCorrections' => Regularization::query()->where('status', RegularizationStatus::Pending)->count(),
        ];
    }
}
