<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Payroll\Events\RunReversed;

final class RunReversedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'payroll.run.reversed';
    }

    public static function label(): string
    {
        return 'automation.triggers.payroll.run.reversed';
    }

    public static function module(): string
    {
        return 'payroll';
    }

    public static function event(): string
    {
        return RunReversed::class;
    }

    public static function requiredPermission(): string
    {
        return 'payroll.runs.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['run_id', 'reverses_run_id', 'period_id', 'net_amount', 'currency'],
            'properties' => [
                'run_id' => ['type' => 'string'],
                'run_number' => ['type' => ['string', 'null']],
                'reverses_run_id' => ['type' => 'string'],
                'period_id' => ['type' => 'string'],
                'payslip_count' => ['type' => 'integer'],
                'net_amount' => ['type' => 'integer'],
                'currency' => ['type' => 'string'],
            ],
        ];
    }
}
