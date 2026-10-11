<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Invoicing\Models\Invoice;

/** An open invoice passed its due date. Raised once per invoice becoming overdue. */
final class InvoiceOverdue extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    private function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function from(Invoice $invoice, int $daysOverdue): self
    {
        return new self([
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'contact_id' => $invoice->contact_id,
            'company_id' => $invoice->company_id,
            'currency' => $invoice->currency,
            'balance_amount' => $invoice->balance_amount,
            'due_on' => $invoice->due_on?->toDateString(),
            'days_overdue' => $daysOverdue,
        ]);
    }

    public static function name(): string
    {
        return 'invoicing.invoice.overdue';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
