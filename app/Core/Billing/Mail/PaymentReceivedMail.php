<?php

declare(strict_types=1);

namespace App\Core\Billing\Mail;

use App\Core\Billing\Models\Payment;
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
 * The receipt: a payment for an invoice was verified or recorded.
 */
final class PaymentReceivedMail extends Mailable implements RequiresTenantContext, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 300;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct(public readonly Payment $payment)
    {
        $this->onQueue('billing');
    }

    public function envelope(): Envelope
    {
        $this->payment->loadMissing('invoice');

        return new Envelope(
            subject: __('Payment received for invoice :number', ['number' => $this->payment->invoice->number]),
        );
    }

    public function content(): Content
    {
        $tenant = app(TenantContext::class)->require();
        $this->payment->loadMissing('invoice');

        return new Content(
            markdown: 'mail.billing.payment-received',
            with: [
                'workspace' => $tenant->name,
                'number' => $this->payment->invoice->number,
                'amount' => Money::format($this->payment->amount, $this->payment->currency),
                'paidAt' => ($this->payment->paid_at ?? $this->payment->created_at)->copy()->timezone($tenant->timezone),
                'reference' => $this->payment->reference,
                'url' => route('core.billing.invoices.show', ['invoice' => $this->payment->invoice_id]),
            ],
        );
    }
}
