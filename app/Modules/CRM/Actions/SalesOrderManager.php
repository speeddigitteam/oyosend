<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\SalesOrderStatusChanged;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesOrderManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function transition(SalesOrder $order, SalesOrderStatus $status): SalesOrder
    {
        return DB::transaction(function () use ($order, $status): SalesOrder {
            /** @var SalesOrder $locked */
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            if ($locked->status !== SalesOrderStatus::Confirmed
                || ! in_array($status, [SalesOrderStatus::Fulfilled, SalesOrderStatus::Canceled], true)) {
                throw ValidationException::withMessages(['status' => __('That sales-order status change is not allowed.')]);
            }

            $locked->status = $status;
            $locked->fulfilled_at = $status === SalesOrderStatus::Fulfilled ? now() : null;
            $locked->canceled_at = $status === SalesOrderStatus::Canceled ? now() : null;
            $locked->save();
            $this->audit->record('crm.sales_order.status_changed', $locked, ['status' => $status->value]);
            SalesOrderStatusChanged::dispatch($locked);

            return $locked;
        });
    }
}
