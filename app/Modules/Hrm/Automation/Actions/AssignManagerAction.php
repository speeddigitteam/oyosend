<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Automation\ActionReceiptStore;
use App\Modules\Hrm\Models\Employee;

/**
 * Sets (or clears) an employee's reporting manager. Cycle-safe; recorded in the
 * employment history; durable receipt makes a retry return the first output.
 */
final class AssignManagerAction implements ActionDefinition
{
    public function __construct(
        private readonly EmployeeManager $employees,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'hrm.employee.assign_manager';
    }

    public static function label(): string
    {
        return 'automation.actions.hrm.employee.assign_manager';
    }

    public static function module(): string
    {
        return 'hrm';
    }

    public static function requiredPermission(): string
    {
        return 'hrm.employees.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id'],
            'properties' => [
                'employee_id' => ['type' => 'string'],
                'manager_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id'],
            'properties' => ['employee_id' => ['type' => 'string'], 'manager_id' => ['type' => ['string', 'null']]],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $employee = Employee::query()->findOrFail((string) ($input['employee_id'] ?? ''));
            $managerId = isset($input['manager_id']) && $input['manager_id'] !== '' ? (string) $input['manager_id'] : null;
            $updated = $this->employees->update($employee, ['manager_id' => $managerId]);

            return ['employee_id' => $updated->id, 'manager_id' => $updated->manager_id];
        });
    }
}
