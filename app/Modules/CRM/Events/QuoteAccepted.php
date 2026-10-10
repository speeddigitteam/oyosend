<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\SalesOrder;

/**
 * Stable Phase 6 hand-off: customer invoicing may consume this event without
 * importing CRM models. The payload is a complete set of identifiers and
 * immutable line snapshots and money totals; invoice creation remains the
 * future module's responsibility.
 */
final class QuoteAccepted extends TenantDomainEvent
{
    /** @var array<string, mixed> */
    private readonly array $data;

    public function __construct(Quote $quote, SalesOrder $order)
    {
        parent::__construct();

        $this->data = [
            'quote_id' => $quote->id,
            'quote_number' => $quote->number,
            'sales_order_id' => $order->id,
            'sales_order_number' => $order->number,
            'contact_id' => $order->contact_id,
            'company_id' => $order->company_id,
            'currency' => $order->currency,
            'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount,
            'total_amount' => $order->total_amount,
            'accepted_at' => $quote->accepted_at?->toIso8601String(),
            'lines' => $order->lines()->orderBy('position')->get()->map(static fn ($line): array => [
                'sales_order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'position' => $line->position,
                'item_type' => $line->item_type->value,
                'sku' => $line->sku,
                'name' => $line->name,
                'unit' => $line->unit,
                'description' => $line->description,
                'quantity_milli' => $line->quantity_milli,
                'currency' => $line->currency,
                'unit_price_amount' => $line->unit_price_amount,
                'discount_amount' => $line->discount_amount,
                'tax_bps' => $line->tax_bps,
                'tax_amount' => $line->tax_amount,
                'line_total_amount' => $line->line_total_amount,
            ])->all(),
        ];
    }

    public static function name(): string
    {
        return 'crm.quote.accepted';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
