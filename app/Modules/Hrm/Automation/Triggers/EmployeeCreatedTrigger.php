<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Hrm\Events\EmployeeCreated;

final class EmployeeCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'hrm.employee.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.hrm.employee.created';
    }

    public static function module(): string
    {
        return 'hrm';
    }

    public static function event(): string
    {
        return EmployeeCreated::class;
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
            'required' => ['employee_id', 'number', 'name'],
            'properties' => ['employee_id' => ['type' => 'string'], 'number' => ['type' => 'string'], 'name' => ['type' => 'string'], 'membership_id' => ['type' => ['string', 'null']], 'department_id' => ['type' => ['string', 'null']], 'branch_id' => ['type' => ['string', 'null']], 'designation_id' => ['type' => ['string', 'null']], 'manager_id' => ['type' => ['string', 'null']], 'employment_type' => ['type' => 'string'], 'joined_on' => ['type' => 'string']],
        ];
    }
}
