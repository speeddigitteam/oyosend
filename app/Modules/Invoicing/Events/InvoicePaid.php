<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Invoicing\Models\Invoice;

/** An invoice has no outstanding balance left (paid, or paid together with credit notes). */
final class InvoicePaid extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    private function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function from(Invoice $invoice): self
    {
        return new self([
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'contact_id' => $invoice->contact_id,
            'company_id' => $invoice->company_id,
            'currency' => $invoice->currency,
            'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'credited_amount' => $invoice->credited_amount,
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ]);
    }

    public static function name(): string
    {
        return 'invoicing.invoice.paid';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
