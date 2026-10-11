<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Support\Money;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentMethod;
use App\Modules\Invoicing\Events\PaymentReceived;
use App\Modules\Invoicing\Events\PaymentReversed;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual payments only: cash, bank transfer, cheque and the like, recorded by a member.
 * Nothing here talks to a payment gateway, and nothing is confirmed by a redirect.
 */
final class PaymentManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InvoiceLedger $ledger,
        private readonly InvoicingSettings $settings,
    ) {}

    /**
     * Record a payment. A repeated idempotency key returns the payment already recorded
     * instead of recording it twice.
     *
     * @param  array{amount: int, method: PaymentMethod|string, reference?: string|null, paid_on?: string|null, notes?: string|null, idempotency_key?: string|null}  $attributes
     */
    public function record(Invoice $invoice, array $attributes, ?TenantMembership $by = null): Payment
    {
        $amount = (int) $attributes['amount'];
        $key = isset($attributes['idempotency_key']) && $attributes['idempotency_key'] !== ''
            ? (string) $attributes['idempotency_key'] : null;

        if ($amount < 1 || $amount > InvoiceMath::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        $method = $attributes['method'] instanceof PaymentMethod
            ? $attributes['method']
            : PaymentMethod::tryFrom((string) $attributes['method'])
                ?? throw ValidationException::withMessages(['method' => __('Choose a payment method.')]);

        try {
            return DB::transaction(function () use ($invoice, $attributes, $amount, $key, $method, $by): Payment {
                /** @var Invoice $locked */
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                if ($key !== null) {
                    $existing = Payment::query()->where('idempotency_key', $key)->first();

                    if ($existing !== null) {
                        if ($existing->invoice_id !== $locked->id || $existing->amount !== $amount) {
                            throw ValidationException::withMessages(['idempotency_key' => __('This payment reference was already used for a different payment.')]);
                        }

                        return $existing;
                    }
                }

                if (! $locked->status->isOpen()) {
                    throw ValidationException::withMessages(['invoice' => __('Payments can only be recorded against an issued invoice with a balance.')]);
                }

                if ($amount > $locked->balance_amount) {
                    throw ValidationException::withMessages([
                        'amount' => __('The payment exceeds the outstanding balance of :balance.', [
                            'balance' => Money::format($locked->balance_amount, $locked->currency),
                        ]),
                    ]);
                }

                $today = $this->settings->today()->toDateString();
                $paidOn = (string) ($attributes['paid_on'] ?? $today);

                if ($paidOn > $today) {
                    throw ValidationException::withMessages(['paid_on' => __('The payment date cannot be in the future.')]);
                }

                $payment = Payment::query()->create([
                    'invoice_id' => $locked->id,
                    'recorded_by' => $by?->id,
                    'amount' => $amount,
                    'currency' => $locked->currency,
                    'method' => $method,
                    'reference' => $this->text($attributes['reference'] ?? null, 120),
                    'paid_on' => $paidOn,
                    'notes' => $this->text($attributes['notes'] ?? null, 2000),
                    'idempotency_key' => $key,
                ]);

                $this->ledger->refresh($locked);
                $this->audit->record('invoicing.payment.recorded', $payment, [
                    'invoice_id' => $locked->id,
                    'invoice_number' => $locked->number,
                    'amount' => $amount,
                    'currency' => $locked->currency,
                    'method' => $method->value,
                ]);
                event(PaymentReceived::from($payment, $locked));

                return $payment;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request with the same key committed first.
            $existing = $key === null ? null : Payment::query()->where('idempotency_key', $key)->first();

            if ($existing === null || $existing->invoice_id !== $invoice->id || $existing->amount !== $amount) {
                throw $exception;
            }

            return $existing;
        }
    }

    /** Reverse a recorded payment. The row stays, flagged, so the history is complete. */
    public function reverse(Payment $payment, string $reason, ?TenantMembership $by = null): Payment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Give a reason for reversing the payment.')]);
        }

        return DB::transaction(function () use ($payment, $reason, $by): Payment {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isReversed()) {
                return $locked;
            }

            if (! $invoice->status->isOpen() && $invoice->status !== InvoiceStatus::Paid) {
                throw ValidationException::withMessages(['payment' => __('This payment cannot be reversed.')]);
            }

            $locked->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $by?->id,
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();

            $this->ledger->refresh($invoice);
            $this->audit->record('invoicing.payment.reversed', $locked, [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'amount' => $locked->amount,
                'reason' => $locked->reversal_reason,
            ]);
            event(PaymentReversed::from($locked, $invoice));

            return $locked;
        });
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
