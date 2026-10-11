<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\ReminderLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Payment reminders: before the due date, on it, and every N days while overdue.
 *
 * Off unless the workspace turned reminders on. Every reminder is reserved in
 * `invoicing_reminder_logs` (unique per invoice and reminder) before it is queued, so
 * running the job twice, or the same hour on two workers, sends nothing twice.
 */
final class ReminderRunner
{
    /** No invoice gets more than this many overdue reminders. */
    public const int MAX_OVERDUE_REMINDERS = 6;

    public function __construct(
        private readonly InvoiceDelivery $delivery,
        private readonly InvoicingSettings $settings,
    ) {}

    /** @return int Reminders queued. */
    public function run(): int
    {
        $settings = $this->settings->current();

        if (! $settings->reminders_enabled) {
            return 0;
        }

        $today = $this->settings->today()->toDateString();
        $sent = 0;

        Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue])
            ->where('balance_amount', '>', 0)
            ->whereNotNull('customer_email')
            ->whereNotNull('due_on')
            ->chunkById(100, function ($invoices) use ($settings, $today, &$sent): void {
                foreach ($invoices as $invoice) {
                    $key = $this->keyFor($invoice, $today, $settings->reminder_before_days, $settings->reminder_overdue_every_days);

                    if ($key !== null && $this->reserveAndSend($invoice, $key, $today)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /** Which reminder is due today for this invoice, if any. */
    public function keyFor(Invoice $invoice, string $today, int $beforeDays, int $overdueEvery): ?string
    {
        if ($invoice->due_on === null) {
            return null;
        }

        $daysToDue = InvoiceLedger::daysBetween($today, $invoice->due_on->toDateString());

        if ($daysToDue > 0) {
            return $beforeDays > 0 && $daysToDue <= $beforeDays ? 'before_due' : null;
        }

        if ($daysToDue === 0) {
            return 'on_due';
        }

        $step = intdiv(-$daysToDue - 1, max(1, $overdueEvery)) + 1;

        return $step <= self::MAX_OVERDUE_REMINDERS ? 'overdue:'.$step : null;
    }

    private function reserveAndSend(Invoice $invoice, string $key, string $today): bool
    {
        if (ReminderLog::query()->where('invoice_id', $invoice->id)->where('reminder_key', $key)->exists()) {
            return false;
        }

        try {
            return DB::transaction(function () use ($invoice, $key, $today): bool {
                ReminderLog::query()->create([
                    'invoice_id' => $invoice->id,
                    'reminder_key' => $key,
                    'sent_to' => (string) $invoice->customer_email,
                    'sent_on' => $today,
                ]);

                $this->delivery->send($invoice, null, null, str_starts_with($key, 'overdue') ? 'overdue' : $key);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (Throwable) {
            // One undeliverable invoice must not stop the rest; the reservation was rolled back, so the next run retries.
            return false;
        }
    }
}
