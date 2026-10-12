<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Payroll\Actions\RunManager;
use App\Modules\Payroll\Automation\ActionReceiptStore;
use App\Modules\Payroll\Models\Period;
use Illuminate\Validation\ValidationException;

/**
 * Calculates the regular run of a pay period as a DRAFT. It never submits or approves it, and
 * nothing is paid: a person still has to submit it and a different person has to approve it.
 * The calculation refuses a period that has not ended or still has open attendance days.
 */
final class PrepareRunAction implements ActionDefinition
{
    public function __construct(
        private readonly RunManager $runs,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'payroll.run.prepare_draft';
    }

    public static function label(): string
    {
        return 'automation.actions.payroll.run.prepare_draft';
    }

    public static function module(): string
    {
        return 'payroll';
    }

    public static function requiredPermission(): string
    {
        return 'payroll.runs.prepare';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['period_id'],
            'properties' => ['period_id' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['run_id', 'status', 'employee_count', 'net_amount'],
            'properties' => [
                'run_id' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'employee_count' => ['type' => 'integer'],
                'net_amount' => ['type' => 'integer'],
                'error_count' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $period = Period::query()->find((string) ($input['period_id'] ?? ''));

            if ($period === null) {
                throw ValidationException::withMessages(['period_id' => __('The pay period was not found.')]);
            }

            $run = $this->runs->prepare($period);

            return [
                'run_id' => $run->id,
                'status' => $run->status->value,
                'employee_count' => $run->employee_count,
                'net_amount' => $run->net_amount,
                'error_count' => count($run->errors ?? []),
            ];
        });
    }
}
