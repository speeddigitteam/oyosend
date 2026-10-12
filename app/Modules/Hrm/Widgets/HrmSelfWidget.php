<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Widgets;

use App\Core\Identity\Models\User;
use App\Modules\Hrm\Actions\EmployeeResolver;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Actions\LeaveBalances;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;

/** The employee's own HR widget, shown with `hrm.self.view`. */
final class HrmSelfWidget
{
    public function __construct(
        private readonly EmployeeResolver $resolver,
        private readonly HrmSettings $settings,
        private readonly LeaveBalances $balances,
    ) {}

    /** @return array{hasProfile: bool, clockedIn: bool, leaveBalanceUnits: int, pendingRequests: int, canClock: bool} */
    public function __invoke(User $user): array
    {
        $employee = $this->resolver->forUser($user);

        if ($employee === null) {
            return ['hasProfile' => false, 'clockedIn' => false, 'leaveBalanceUnits' => 0, 'pendingRequests' => 0, 'canClock' => false];
        }

        $year = $this->settings->leaveYear($this->settings->today()->toDateString());
        $tracked = LeaveType::query()->where('is_active', true)->where('tracks_balance', true)->pluck('id')->all();
        $balances = $this->balances->forEmployee($employee->id, $year);

        return [
            'hasProfile' => true,
            'clockedIn' => AttendanceEntry::query()->where('employee_id', $employee->id)->whereNull('clock_out_at')->exists(),
            'leaveBalanceUnits' => (int) array_sum(array_intersect_key($balances, array_flip($tracked))),
            'pendingRequests' => LeaveRequest::query()->where('employee_id', $employee->id)
                ->whereIn('status', [LeaveStatus::PendingManager, LeaveStatus::PendingHr])->count(),
            'canClock' => $employee->status->isWorking() && $user->can('hrm.attendance.clock'),
        ];
    }
}
