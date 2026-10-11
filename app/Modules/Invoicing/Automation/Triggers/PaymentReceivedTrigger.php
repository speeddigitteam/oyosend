<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Invoicing\Events\PaymentReceived;

final class PaymentReceivedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'invoicing.payment.received';
    }

    public static function label(): string
    {
        return 'automation.triggers.invoicing.payment.received';
    }

    public static function module(): string
    {
        return 'invoicing';
    }

    public static function event(): string
    {
        return PaymentReceived::class;
    }

    public static function requiredPermission(): string
    {
        return 'invoicing.invoices.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['payment_id', 'invoice_id', 'amount', 'currency'], 'properties' => ['payment_id' => ['type' => 'string'], 'invoice_id' => ['type' => 'string'], 'amount' => ['type' => 'integer'], 'currency' => ['type' => 'string'], 'method' => ['type' => 'string'], 'balance_amount' => ['type' => 'integer']]];
    }
}
