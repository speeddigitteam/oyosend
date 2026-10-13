<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\SalesOrder;

/** A confirmed sales order was fulfilled or canceled. Ids, the number, the new status and the total only. */
final class SalesOrderStatusChanged extends TenantDomainEvent
{
    /** @var array<string, scalar|null> */
    private readonly array $data;

    public function __construct(SalesOrder $order)
    {
        parent::__construct();

        $this->data = [
            'sales_order_id' => $order->id,
            'sales_order_number' => $order->number,
            'status' => $order->status->value,
            'contact_id' => $order->contact_id,
            'company_id' => $order->company_id,
            'currency' => $order->currency,
            'total_amount' => $order->total_amount,
        ];
    }

    public static function name(): string
    {
        return 'crm.sales_order.status_changed';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
