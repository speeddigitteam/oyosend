<?php

declare(strict_types=1);

namespace App\Core\Billing\Mail;

use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Support\Money;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the workspace owner that an invoice from the platform is waiting.
 */
final class InvoiceIssuedMail extends Mailable implements RequiresTenantContext, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Invoice $invoice)
    {
        $this->onQueue('billing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Invoice :number for :workspace', [
                'number' => $this->invoice->number,
                'workspace' => app(TenantContext::class)->require()->name,
            ]),
        );
    }

    public function content(): Content
    {
        $tenant = app(TenantContext::class)->require();
        $this->invoice->loadMissing('plan');

        return new Content(
            markdown: 'mail.billing.invoice-issued',
            with: [
                'workspace' => $tenant->name,
                'number' => $this->invoice->number,
                'plan' => $this->invoice->plan?->name,
                'total' => Money::format($this->invoice->total_amount, $this->invoice->currency),
                // Days as the workspace's clock has them; the view writes them in the mail's language.
                'dueAt' => $this->invoice->due_at?->copy()->timezone($tenant->timezone),
                'periodStart' => $this->invoice->period_start?->copy()->timezone($tenant->timezone),
                'periodEnd' => $this->invoice->period_end?->copy()->timezone($tenant->timezone),
                'url' => route('core.billing.invoices.show', ['invoice' => $this->invoice->getKey()]),
            ],
        );
    }
}
