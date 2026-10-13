<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Mail;

use App\Core\Billing\Support\Money;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Actions\InvoiceDocument;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invoice, or a payment reminder for one, sent to the customer with the printable
 * document attached. There is no public link: the document travels with the message.
 */
final class InvoiceMail extends Mailable implements RequiresTenantContext, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    /**
     * @param  string|null  $reminder  null for the invoice itself, otherwise `before_due`, `on_due` or `overdue`.
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly ?string $note = null,
        public readonly ?string $reminder = null,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        $invoice = $this->invoice();
        $workspace = app(TenantContext::class)->require()->name;

        return new Envelope(
            subject: $this->reminder === null
                ? __('Invoice :number from :workspace', ['number' => $invoice->number, 'workspace' => $workspace])
                : __('Payment reminder: invoice :number from :workspace', ['number' => $invoice->number, 'workspace' => $workspace]),
        );
    }

    public function content(): Content
    {
        $invoice = $this->invoice();
        $tenant = app(TenantContext::class)->require();

        return new Content(
            markdown: 'mail.invoicing.invoice',
            with: [
                'workspace' => $tenant->name,
                'customer' => $invoice->customer_name,
                'number' => $invoice->number,
                'total' => Money::format($invoice->total_amount, $invoice->currency),
                'balance' => Money::format($invoice->balance_amount, $invoice->currency),
                'dueOn' => $invoice->due_on?->copy()->locale(app()->getLocale()),
                'reminder' => $this->reminder,
                'note' => $this->note,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $invoice = $this->invoice();

        return [
            Attachment::fromData(fn (): string => app(InvoiceDocument::class)->html($invoice), 'invoice-'.$invoice->number.'.html')
                ->withMime('text/html'),
        ];
    }

    private function invoice(): Invoice
    {
        return Invoice::query()->with(['lines', 'contact', 'company'])->findOrFail($this->invoiceId);
    }
}
