<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Mail\InvoiceMail;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Queues an invoice (or a reminder) to the customer in the workspace language. */
final class InvoiceDelivery
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function send(Invoice $invoice, ?string $email = null, ?string $note = null, ?string $reminder = null): Invoice
    {
        $recipient = $this->recipient($invoice, $email);

        return DB::transaction(function () use ($invoice, $recipient, $note, $reminder): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true)) {
                throw ValidationException::withMessages(['invoice' => __('Only an issued invoice can be sent.')]);
            }

            // A reminder was decided before the lock was taken: a payment or void may have landed since.
            if ($reminder !== null && (! in_array($locked->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true)
                || $locked->balance_amount < 1)) {
                throw ValidationException::withMessages(['invoice' => __('This invoice has no open balance to remind about.')]);
            }

            if ($reminder === null) {
                $locked->sent_at = now();
                $locked->save();
            }

            $this->audit->record($reminder === null ? 'invoicing.invoice.sent' : 'invoicing.invoice.reminder_sent', $locked, [
                'number' => $locked->number,
                'to' => $recipient,
                'reminder' => $reminder,
            ]);

            Mail::to($recipient)
                ->locale($this->context->require()->locale)
                ->queue(new InvoiceMail($locked->id, $note, $reminder));

            return $locked;
        });
    }

    private function recipient(Invoice $invoice, ?string $email): string
    {
        $address = $email !== null && trim($email) !== '' ? trim($email) : $invoice->customer_email;

        if ($address === null || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('Enter an email address to send the invoice to.')]);
        }

        return $address;
    }
}
