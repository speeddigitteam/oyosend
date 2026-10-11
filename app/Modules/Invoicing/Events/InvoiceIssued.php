<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Invoicing\Models\Invoice;

/** An invoice received its number and left draft. Ids and scalars only. */
final class InvoiceIssued extends TenantDomainEvent
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
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'source_type' => $invoice->source_type,
            'source_id' => $invoice->source_id,
        ]);
    }

    public static function name(): string
    {
        return 'invoicing.invoice.issued';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
