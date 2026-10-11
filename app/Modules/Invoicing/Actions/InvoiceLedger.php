<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Events\InvoiceOverdue;
use App\Modules\Invoicing\Events\InvoicePaid;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;
use LogicException;

/**
 * The one place an invoice balance and status are derived.
 *
 * paid_amount, credited_amount and balance_amount are always recomputed from the
 * payment and credit-note rows, never incremented, so a retry or a reversal cannot
 * drift them. Call it with the invoice row locked, inside the transaction that
 * changed a payment or credit note.
 */
final class InvoiceLedger
{
    public function __construct(private readonly InvoicingSettings $settings) {}

    public function refresh(Invoice $invoice): Invoice
    {
        if ($invoice->status === InvoiceStatus::Draft || $invoice->status === InvoiceStatus::Void) {
            throw new LogicException('Only an issued invoice has a ledger.');
        }

        $previous = $invoice->status;
        $paid = InvoiceMath::sum(Payment::query()
            ->where('invoice_id', $invoice->id)->whereNull('reversed_at')->pluck('amount')->all());
        $credited = InvoiceMath::sum(CreditNote::query()
            ->where('invoice_id', $invoice->id)->pluck('total_amount')->all());
        $balance = max(0, $invoice->total_amount - $paid - $credited);
        $today = $this->settings->today()->toDateString();
        $pastDue = $invoice->due_on !== null && $invoice->due_on->toDateString() < $today;

        $status = match (true) {
            $balance === 0 => InvoiceStatus::Paid,
            $previous === InvoiceStatus::Overdue => InvoiceStatus::Overdue,
            // A reopened invoice (a reversed payment) that is past due is overdue again.
            $previous === InvoiceStatus::Paid && $pastDue => InvoiceStatus::Overdue,
            $paid > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };

        $invoice->paid_amount = $paid;
        $invoice->credited_amount = $credited;
        $invoice->balance_amount = $balance;
        $invoice->status = $status;
        $invoice->paid_at = $status === InvoiceStatus::Paid ? ($invoice->paid_at ?? now()) : null;

        if ($status === InvoiceStatus::Overdue && $previous !== InvoiceStatus::Overdue) {
            $invoice->overdue_at = now();
        }

        $invoice->save();

        if ($status === InvoiceStatus::Paid && $previous !== InvoiceStatus::Paid) {
            event(InvoicePaid::from($invoice));
        }

        if ($status === InvoiceStatus::Overdue && $previous !== InvoiceStatus::Overdue) {
            event(InvoiceOverdue::from($invoice, max(1, self::daysBetween($invoice->due_on?->toDateString() ?? $today, $today))));
        }

        return $invoice;
    }

    /** Whole days from one Y-m-d date to a later one. */
    public static function daysBetween(string $from, string $to): int
    {
        return intdiv(strtotime($to.' UTC') - strtotime($from.' UTC'), 86400);
    }
}
