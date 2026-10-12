<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Payroll\Events\PayslipPaid;

final class PayslipPaidTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'payroll.payslip.paid';
    }

    public static function label(): string
    {
        return 'automation.triggers.payroll.payslip.paid';
    }

    public static function module(): string
    {
        return 'payroll';
    }

    public static function event(): string
    {
        return PayslipPaid::class;
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
            'required' => ['payslip_id', 'run_id', 'employee_id', 'net_amount', 'currency'],
            'properties' => [
                'payslip_id' => ['type' => 'string'],
                'payslip_number' => ['type' => ['string', 'null']],
                'run_id' => ['type' => 'string'],
                'period_id' => ['type' => 'string'],
                'employee_id' => ['type' => 'string'],
                'membership_id' => ['type' => ['string', 'null']],
                'net_amount' => ['type' => 'integer'],
                'currency' => ['type' => 'string'],
                'method' => ['type' => ['string', 'null']],
                'paid_on' => ['type' => ['string', 'null']],
                'is_reversal' => ['type' => 'boolean'],
            ],
        ];
    }
}
