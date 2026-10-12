<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Hrm\Events\LeaveApproved;

final class LeaveApprovedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'hrm.leave.approved';
    }

    public static function label(): string
    {
        return 'automation.triggers.hrm.leave.approved';
    }

    public static function module(): string
    {
        return 'hrm';
    }

    public static function event(): string
    {
        return LeaveApproved::class;
    }

    public static function requiredPermission(): string
    {
        return 'hrm.leave.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['leave_request_id', 'employee_id', 'units'],
            'properties' => ['leave_request_id' => ['type' => 'string'], 'employee_id' => ['type' => 'string'], 'membership_id' => ['type' => ['string', 'null']], 'leave_type_id' => ['type' => 'string'], 'leave_type_code' => ['type' => 'string'], 'is_paid' => ['type' => 'boolean'], 'starts_on' => ['type' => 'string'], 'ends_on' => ['type' => 'string'], 'units' => ['type' => 'integer']],
        ];
    }
}
