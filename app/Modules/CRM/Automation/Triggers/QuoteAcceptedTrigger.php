<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\CRM\Events\QuoteAccepted;

/** A quotation was accepted and its sales order confirmed: the moment for an order confirmation message. */
final class QuoteAcceptedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'crm.quote.accepted';
    }

    public static function label(): string
    {
        return 'automation.triggers.crm.quote.accepted';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function event(): string
    {
        return QuoteAccepted::class;
    }

    public static function requiredPermission(): string
    {
        return 'crm.orders.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['minimum_total_amount' => ['type' => 'integer', 'minimum' => 0]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['quote_id', 'sales_order_id', 'sales_order_number', 'currency', 'total_amount'],
            'properties' => [
                'quote_id' => ['type' => 'string'],
                'quote_number' => ['type' => 'string'],
                'sales_order_id' => ['type' => 'string'],
                'sales_order_number' => ['type' => 'string'],
                'contact_id' => ['type' => ['string', 'null']],
                'company_id' => ['type' => ['string', 'null']],
                'currency' => ['type' => 'string'],
                'total_amount' => ['type' => 'integer'],
            ],
        ];
    }
}
