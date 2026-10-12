<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Support;

/** The permission names the HRM module declares. */
final class HrmPermissions
{
    public const string EMPLOYEES_VIEW = 'hrm.employees.view';

    public const string EMPLOYEES_CREATE = 'hrm.employees.create';

    public const string EMPLOYEES_MANAGE = 'hrm.employees.manage';

    public const string EMPLOYEES_LIFECYCLE = 'hrm.employees.lifecycle';

    public const string PERSONAL_VIEW = 'hrm.personal.view';

    public const string SELF_VIEW = 'hrm.self.view';

    public const string ATTENDANCE_VIEW = 'hrm.attendance.view';

    public const string ATTENDANCE_MANAGE = 'hrm.attendance.manage';

    public const string ATTENDANCE_CLOCK = 'hrm.attendance.clock';

    public const string ATTENDANCE_REGULARIZE = 'hrm.attendance.regularize';

    public const string ATTENDANCE_APPROVE = 'hrm.attendance.approve';

    public const string LEAVE_VIEW = 'hrm.leave.view';

    public const string LEAVE_REQUEST = 'hrm.leave.request';

    public const string LEAVE_APPROVE = 'hrm.leave.approve';

    public const string LEAVE_FINALIZE = 'hrm.leave.finalize';

    public const string LEAVE_MANAGE = 'hrm.leave.manage';

    public const string HOLIDAYS_VIEW = 'hrm.holidays.view';

    public const string HOLIDAYS_MANAGE = 'hrm.holidays.manage';

    public const string REPORTS_VIEW = 'hrm.reports.view';

    public const string SETTINGS_MANAGE = 'hrm.settings.manage';
}
