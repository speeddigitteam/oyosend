<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum LeaveApprovalFlow: string
{
    case ManagerThenHr = 'manager_then_hr';
    case ManagerOnly = 'manager_only';
    case HrOnly = 'hr_only';
}
