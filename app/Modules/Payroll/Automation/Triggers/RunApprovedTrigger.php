<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Payroll\Events\RunApproved;

final class RunApprovedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'payroll.run.approved';
    }

    public static function label(): string
    {
        return 'automation.triggers.payroll.run.approved';
    }

    public static function module(): string
    {
        return 'payroll';
    }

    public static function event(): string
    {
        return RunApproved::class;
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
            'required' => ['run_id', 'period_id', 'kind', 'payslip_count', 'net_amount', 'currency'],
            'properties' => [
                'run_id' => ['type' => 'string'],
                'run_number' => ['type' => ['string', 'null']],
                'period_id' => ['type' => 'string'],
                'kind' => ['type' => 'string'],
                'payslip_count' => ['type' => 'integer'],
                'gross_amount' => ['type' => 'integer'],
                'net_amount' => ['type' => 'integer'],
                'currency' => ['type' => 'string'],
            ],
        ];
    }
}
