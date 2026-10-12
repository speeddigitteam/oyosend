<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Hrm\Events\AttendanceDayClosed;

final class AttendanceDayClosedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'hrm.attendance.day_closed';
    }

    public static function label(): string
    {
        return 'automation.triggers.hrm.attendance.day_closed';
    }

    public static function module(): string
    {
        return 'hrm';
    }

    public static function event(): string
    {
        return AttendanceDayClosed::class;
    }

    public static function requiredPermission(): string
    {
        return 'hrm.attendance.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id', 'work_date', 'status'],
            'properties' => ['attendance_day_id' => ['type' => 'string'], 'employee_id' => ['type' => 'string'], 'membership_id' => ['type' => ['string', 'null']], 'work_date' => ['type' => 'string'], 'status' => ['type' => 'string'], 'scheduled_minutes' => ['type' => 'integer'], 'worked_minutes' => ['type' => 'integer'], 'late_minutes' => ['type' => 'integer'], 'early_leave_minutes' => ['type' => 'integer'], 'overtime_minutes' => ['type' => 'integer'], 'leave_units' => ['type' => 'integer'], 'revision' => ['type' => 'integer']],
        ];
    }
}
