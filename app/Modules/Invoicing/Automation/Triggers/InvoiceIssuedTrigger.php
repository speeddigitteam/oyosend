<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Invoicing\Events\InvoiceIssued;

final class InvoiceIssuedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'invoicing.invoice.issued';
    }

    public static function label(): string
    {
        return 'automation.triggers.invoicing.invoice.issued';
    }

    public static function module(): string
    {
        return 'invoicing';
    }

    public static function event(): string
    {
        return InvoiceIssued::class;
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
        return ['type' => 'object', 'required' => ['invoice_id', 'number', 'currency', 'total_amount'], 'properties' => ['invoice_id' => ['type' => 'string'], 'number' => ['type' => 'string'], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']], 'currency' => ['type' => 'string'], 'total_amount' => ['type' => 'integer'], 'due_on' => ['type' => 'string']]];
    }
}
