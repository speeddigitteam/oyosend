<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Hrm\Events\EmployeeTerminated;

final class EmployeeTerminatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'hrm.employee.terminated';
    }

    public static function label(): string
    {
        return 'automation.triggers.hrm.employee.terminated';
    }

    public static function module(): string
    {
        return 'hrm';
    }

    public static function event(): string
    {
        return EmployeeTerminated::class;
    }

    public static function requiredPermission(): string
    {
        return 'hrm.employees.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id', 'number', 'terminated_on'],
            'properties' => ['employee_id' => ['type' => 'string'], 'number' => ['type' => 'string'], 'name' => ['type' => 'string'], 'membership_id' => ['type' => ['string', 'null']], 'termination_type' => ['type' => ['string', 'null']], 'terminated_on' => ['type' => 'string'], 'last_working_on' => ['type' => ['string', 'null']]],
        ];
    }
}
