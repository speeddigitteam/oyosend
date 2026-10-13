<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\CRM\Events\SalesOrderStatusChanged;

/** A sales order was fulfilled or canceled. `status: fulfilled` starts a workflow only for delivered orders. */
final class SalesOrderStatusChangedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'crm.sales_order.status_changed';
    }

    public static function label(): string
    {
        return 'automation.triggers.crm.sales_order.status_changed';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function event(): string
    {
        return SalesOrderStatusChanged::class;
    }

    public static function requiredPermission(): string
    {
        return 'crm.orders.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['fulfilled', 'canceled']]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['sales_order_id', 'sales_order_number', 'status', 'currency', 'total_amount'],
            'properties' => [
                'sales_order_id' => ['type' => 'string'],
                'sales_order_number' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'contact_id' => ['type' => ['string', 'null']],
                'company_id' => ['type' => ['string', 'null']],
                'currency' => ['type' => 'string'],
                'total_amount' => ['type' => 'integer'],
            ],
        ];
    }
}
