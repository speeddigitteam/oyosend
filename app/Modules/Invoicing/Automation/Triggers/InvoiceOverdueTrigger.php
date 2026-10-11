<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Invoicing\Events\InvoiceOverdue;

final class InvoiceOverdueTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'invoicing.invoice.overdue';
    }

    public static function label(): string
    {
        return 'automation.triggers.invoicing.invoice.overdue';
    }

    public static function module(): string
    {
        return 'invoicing';
    }

    public static function event(): string
    {
        return InvoiceOverdue::class;
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
        return ['type' => 'object', 'required' => ['invoice_id', 'balance_amount', 'currency', 'days_overdue'], 'properties' => ['invoice_id' => ['type' => 'string'], 'number' => ['type' => 'string'], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']], 'currency' => ['type' => 'string'], 'balance_amount' => ['type' => 'integer'], 'days_overdue' => ['type' => 'integer']]];
    }
}
