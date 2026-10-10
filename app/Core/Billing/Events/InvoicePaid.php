<?php

declare(strict_types=1);

namespace App\Core\Billing\Events;

use App\Core\Billing\Models\Invoice;
use App\Core\Events\TenantDomainEvent;

/**
 * An invoice from the platform to the workspace was paid.
 */
final class InvoicePaid extends TenantDomainEvent
{
    public readonly string $invoiceId;

    public readonly string $number;

    public readonly int $totalAmount;

    public readonly string $currency;

    public function __construct(Invoice $invoice)
    {
        parent::__construct();

        $this->invoiceId = $invoice->id;
        $this->number = $invoice->number;
        $this->totalAmount = $invoice->total_amount;
        $this->currency = $invoice->currency;
    }

    public static function name(): string
    {
        return 'core.invoice.paid';
    }

    public function payload(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'number' => $this->number,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
        ];
    }
}
