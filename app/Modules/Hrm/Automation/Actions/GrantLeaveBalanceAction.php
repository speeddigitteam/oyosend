<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Hrm\Actions\LeaveAdjustments;
use App\Modules\Hrm\Actions\LeaveBalances;
use App\Modules\Hrm\Automation\ActionReceiptStore;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Validation\ValidationException;

/**
 * Adds leave units to an employee's current leave year. Only grants (positive units);
 * a deduction stays a human decision. The durable receipt makes a retry return the
 * first result instead of granting twice.
 */
final class GrantLeaveBalanceAction implements ActionDefinition
{
    public function __construct(
        private readonly LeaveAdjustments $adjustments,
        private readonly LeaveBalances $balances,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'hrm.leave.grant_balance';
    }

    public static function label(): string
    {
        return 'automation.actions.hrm.leave.grant_balance';
    }

    public static function module(): string
    {
        return 'hrm';
    }

    public static function requiredPermission(): string
    {
        return 'hrm.leave.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id', 'leave_type_id', 'units', 'note'],
            'properties' => [
                'employee_id' => ['type' => 'string'],
                'leave_type_id' => ['type' => 'string'],
                'units' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 732],
                'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ledger_entry_id', 'balance_units'],
            'properties' => ['ledger_entry_id' => ['type' => 'string'], 'balance_units' => ['type' => 'integer']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            $units = (int) ($input['units'] ?? 0);

            if ($units < 1) {
                throw ValidationException::withMessages(['units' => __('The adjustment cannot be zero.')]);
            }

            $employee = Employee::query()->findOrFail((string) ($input['employee_id'] ?? ''));
            $type = LeaveType::query()->findOrFail((string) ($input['leave_type_id'] ?? ''));
            $entry = $this->adjustments->adjust($employee, $type, $units, (string) ($input['note'] ?? ''), null, $idempotencyKey);

            return [
                'ledger_entry_id' => $entry->id,
                'balance_units' => $this->balances->balance($employee->id, $type->id, $entry->leave_year),
            ];
        });
    }
}
