<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Support\Money;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Invoicing\Events\CreditNoteIssued;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\CreditNoteLine;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A credit note reduces what a customer owes on one invoice. It is itself a numbered,
 * immutable document and can never take the balance below zero.
 */
final class CreditNoteManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InvoiceLedger $ledger,
        private readonly InvoiceLines $lineBuilder,
        private readonly InvoicingSettings $settings,
        private readonly NumberAllocator $numbers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function issue(Invoice $invoice, string $reason, array $lines, ?TenantMembership $by = null): CreditNote
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Give a reason for the credit note.')]);
        }

        $prepared = $this->lineBuilder->prepare($lines);

        return DB::transaction(function () use ($invoice, $reason, $prepared, $by): CreditNote {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['invoice' => __('A credit note can only be issued against an invoice with an outstanding balance.')]);
            }

            $total = $prepared['totals']['total_amount'];

            if ($total < 1) {
                throw ValidationException::withMessages(['lines' => __('A credit note needs a positive total.')]);
            }

            if ($total > $locked->balance_amount) {
                throw ValidationException::withMessages([
                    'lines' => __('The credit note exceeds the outstanding balance of :balance.', [
                        'balance' => Money::format($locked->balance_amount, $locked->currency),
                    ]),
                ]);
            }

            $settings = $this->settings->current();
            $today = $this->settings->today();
            $note = CreditNote::query()->create([
                'invoice_id' => $locked->id,
                'issued_by' => $by?->id,
                'number' => $this->numbers->next(NumberAllocator::CREDIT_NOTE, $today, $settings),
                'currency' => $locked->currency,
                'issued_on' => $today->toDateString(),
                'reason' => mb_substr($reason, 0, 500),
                ...$prepared['totals'],
            ]);

            foreach ($prepared['lines'] as $line) {
                unset($line['tax_rate_id']);
                CreditNoteLine::query()->create([...$line, 'credit_note_id' => $note->id]);
            }

            $this->ledger->refresh($locked);
            $this->audit->record('invoicing.credit_note.applied', $note, [
                'invoice_id' => $locked->id,
                'invoice_number' => $locked->number,
                'number' => $note->number,
                'total_amount' => $note->total_amount,
                'reason' => $note->reason,
            ]);
            event(CreditNoteIssued::from($note, $locked));

            return $note->load('lines');
        });
    }
}
