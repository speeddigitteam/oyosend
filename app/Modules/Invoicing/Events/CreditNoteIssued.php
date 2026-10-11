<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\Invoice;

/** A credit note was issued against an invoice and applied to its balance. */
final class CreditNoteIssued extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    private function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function from(CreditNote $note, Invoice $invoice): self
    {
        return new self([
            'credit_note_id' => $note->id,
            'number' => $note->number,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'contact_id' => $invoice->contact_id,
            'company_id' => $invoice->company_id,
            'currency' => $note->currency,
            'total_amount' => $note->total_amount,
            'balance_amount' => $invoice->balance_amount,
        ]);
    }

    public static function name(): string
    {
        return 'invoicing.credit_note.issued';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
