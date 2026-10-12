<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Payroll\Actions\ItemManager;
use App\Modules\Payroll\Automation\ActionReceiptStore;
use App\Modules\Payroll\Models\Period;
use Illuminate\Validation\ValidationException;

/**
 * Adds a pending one-off pay item (an allowance, bonus, deduction or reimbursement) to a pay period.
 * It only creates a pending item: it is paid out only if a person later approves a run that includes
 * it. The durable receipt makes a retry return the first result instead of adding a second item.
 */
final class CreatePayItemAction implements ActionDefinition
{
    public function __construct(
        private readonly ItemManager $items,
        private readonly EmployeeDirectory $directory,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'payroll.item.create';
    }

    public static function label(): string
    {
        return 'automation.actions.payroll.item.create';
    }

    public static function module(): string
    {
        return 'payroll';
    }

    public static function requiredPermission(): string
    {
        return 'payroll.items.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id', 'period_id', 'type', 'name'],
            'properties' => [
                'employee_id' => ['type' => 'string'],
                'period_id' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => ['allowance', 'bonus', 'festival_bonus', 'deduction', 'reimbursement']],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'amount' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'basic_bps' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 100000],
                'is_taxable' => ['type' => 'boolean'],
                'note' => ['type' => ['string', 'null'], 'maxLength' => 1000],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['item_id', 'status'],
            'properties' => ['item_id' => ['type' => 'string'], 'status' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $employee = $this->directory->find((string) ($input['employee_id'] ?? ''));
            $period = Period::query()->find((string) ($input['period_id'] ?? ''));

            if ($employee === null || $period === null) {
                throw ValidationException::withMessages(['employee_id' => __('The employee or the pay period was not found.')]);
            }

            $item = $this->items->create($employee, $period, $input);

            return ['item_id' => $item->id, 'status' => $item->status->value];
        });
    }
}
