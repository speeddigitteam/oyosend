<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Invoicing\Events\CreditNoteIssued;

final class CreditNoteIssuedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'invoicing.credit_note.issued';
    }

    public static function label(): string
    {
        return 'automation.triggers.invoicing.credit_note.issued';
    }

    public static function module(): string
    {
        return 'invoicing';
    }

    public static function event(): string
    {
        return CreditNoteIssued::class;
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
        return ['type' => 'object', 'required' => ['credit_note_id', 'invoice_id', 'total_amount', 'currency'], 'properties' => ['credit_note_id' => ['type' => 'string'], 'invoice_id' => ['type' => 'string'], 'number' => ['type' => 'string'], 'total_amount' => ['type' => 'integer'], 'currency' => ['type' => 'string'], 'balance_amount' => ['type' => 'integer']]];
    }
}
