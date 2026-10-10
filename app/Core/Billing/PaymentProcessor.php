<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Gateways\PaymentRedirect;
use App\Core\Billing\Gateways\PaymentRequest;
use App\Core\Billing\Mail\PaymentReceivedMail;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Support\BillingContact;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Platform\Models\PlatformUser;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Takes payments for the invoices of the active workspace.
 *
 * Online: {@see begin()} opens a payment at a gateway, {@see confirm()} asks
 * the gateway what became of it - as often as the gateway notifies us, with
 * the same result. Offline: an operator records money received outside any
 * gateway ({@see recordOffline()}).
 *
 * In both cases the invoice is settled here and nowhere else, and only for a
 * payment of exactly the invoice's amount in its currency.
 */
final class PaymentProcessor
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GatewayRegistry $gateways,
        private readonly InvoiceSettlement $settlement,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start paying an invoice through a gateway.
     *
     * @throws ValidationException
     */
    public function begin(Invoice $invoice, string $gatewayKey, User $payer): PaymentRedirect
    {
        $this->guardPayable($invoice);

        $gateway = $this->gateways->find($gatewayKey);

        if ($gateway === null || ! $gateway->isConfigured() || ! $gateway->supportsCurrency($invoice->currency)) {
            throw ValidationException::withMessages(['gateway' => __('This payment method is not available.')]);
        }

        $tenant = $this->context->require();

        /** @var Payment $payment */
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->getKey(),
            'gateway' => $gateway->key(),
            'status' => PaymentStatus::Pending,
            'currency' => $invoice->currency,
            'amount' => $invoice->total_amount,
            // A ULID: unique, unguessable, and within every gateway's length limit.
            'reference' => (string) Str::ulid(),
        ]);

        try {
            $redirect = $gateway->initiate($payment, new PaymentRequest(
                // Not translated: it is shown by the provider, in the provider's
                // own language, and some accept nothing but ASCII here.
                description: 'Invoice '.$invoice->number,
                customerName: $tenant->legal_name ?? $tenant->name,
                customerEmail: $tenant->email ?? $payer->email,
                customerPhone: $tenant->phone,
                address: $tenant->address,
                city: $tenant->city,
                postalCode: $tenant->postal_code,
                country: $tenant->country,
                returnUrl: route('core.billing.payments.return', ['reference' => $payment->reference]),
                notifyUrl: route('core.billing.gateways.notify', ['gateway' => $gateway->key()]),
            ));
        } catch (GatewayException $exception) {
            $this->fail($payment, $exception->getMessage());

            report($exception);

            throw ValidationException::withMessages([
                'gateway' => $exception->isMissingBillingDetails()
                    ? __('Add the company phone number, address, city, postal code and country in the workspace settings before paying online.')
                    : __('The payment could not be started. Please try again, or choose another payment method.'),
            ]);
        }

        $payment->update(['details' => $redirect->details === [] ? null : $redirect->details]);

        $this->audit->record('billing.payment_started', $payment, ['gateway' => $gateway->key(), 'invoice' => $invoice->number]);

        return $redirect;
    }

    /**
     * Find out from the gateway what became of a payment and act on it.
     *
     * Safe to call any number of times, from the payer's return and from the
     * gateway's notification alike: a payment that has reached a final state
     * is returned as it is.
     *
     * @param  array<string, mixed>  $payload  What the gateway sent. Never trusted on its own.
     */
    public function confirm(Payment $payment, array $payload = []): Payment
    {
        if ($payment->status->isFinal() || $payment->gateway === Payment::OFFLINE) {
            return $payment;
        }

        $gateway = $this->gateways->find($payment->gateway);

        if ($gateway === null || ! $gateway->isConfigured()) {
            return $payment;
        }

        try {
            // Outside the transaction: it is a call to another company's server.
            $verified = $gateway->verify($payment, $payload);
        } catch (GatewayException $exception) {
            // Still pending: the gateway will notify again, or the payer can come back.
            report($exception);

            return $payment;
        }

        return DB::transaction(function () use ($payment, $verified, $gateway): Payment {
            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($payment->status->isFinal()) {
                return $payment;
            }

            if ($verified->status === PaymentStatus::Pending) {
                if ($verified->failureReason !== null) {
                    $payment->update(['failure_reason' => $verified->failureReason, 'details' => $verified->details ?: $payment->details]);
                }

                return $payment;
            }

            if ($verified->status !== PaymentStatus::Succeeded) {
                return $this->fail($payment, $verified->failureReason, $verified->status);
            }

            if ($verified->amount !== $payment->amount || strtoupper((string) $verified->currency) !== $payment->currency) {
                return $this->fail($payment, 'The gateway confirmed a different amount or currency than the invoice.');
            }

            try {
                $payment->update([
                    'status' => PaymentStatus::Succeeded,
                    'gateway_reference' => $verified->gatewayReference,
                    'method' => $verified->method,
                    'paid_at' => Carbon::now(),
                    'failure_reason' => null,
                    'details' => $verified->details ?: $payment->details,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The provider's transaction is already recorded against another payment.
                return $this->fail($payment->refresh(), 'This gateway transaction was already recorded.');
            }

            $this->audit->record('billing.payment_received', $payment, [
                'gateway' => $gateway->key(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ]);

            $this->applyTo($payment);

            return $payment;
        });
    }

    /**
     * Record money received outside a gateway (bank transfer, cash, cheque)
     * and settle the invoice. An operator vouches for it; it is audited under
     * their name in the workspace's own trail.
     *
     * @throws ValidationException
     */
    public function recordOffline(
        Invoice $invoice,
        PlatformUser $operator,
        string $method,
        ?string $reference = null,
        ?string $note = null,
        ?CarbonInterface $paidAt = null,
    ): Payment {
        return DB::transaction(function () use ($invoice, $operator, $method, $reference, $note, $paidAt): Payment {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            $this->guardPayable($invoice);

            /** @var Payment $payment */
            $payment = Payment::query()->create([
                'invoice_id' => $invoice->getKey(),
                'gateway' => Payment::OFFLINE,
                'method' => $method,
                'status' => PaymentStatus::Succeeded,
                'currency' => $invoice->currency,
                'amount' => $invoice->total_amount,
                'reference' => (string) Str::ulid(),
                'paid_at' => $paidAt ?? Carbon::now(),
                'details' => array_filter(['bank_reference' => $reference, 'note' => $note]) ?: null,
                'recorded_by' => $operator->getKey(),
            ]);

            $this->audit->record('billing.payment_recorded', $payment, [
                'method' => $method,
                'invoice' => $invoice->number,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ], $operator);

            $this->applyTo($payment);

            return $payment;
        });
    }

    /**
     * Settle the invoice a successful payment was made for, and send the receipt.
     */
    private function applyTo(Payment $payment): void
    {
        $invoice = $payment->invoice()->firstOrFail();

        if (! $this->settlement->settle($invoice, $payment->paid_at)) {
            // Money arrived for an invoice that was withdrawn or already paid
            // while the payer was at the gateway. It is kept on record and
            // flagged; putting it right (a refund, a credit) is an operator's call.
            $this->audit->record('billing.payment_unapplied', $payment, [
                'invoice' => $invoice->number,
                'invoice_status' => $invoice->status->value,
            ]);
        }

        if (($contact = BillingContact::ofActiveWorkspace()) !== null) {
            // Queued once the payment is committed, inside this workspace wherever that happens.
            $this->context->afterCommit(static function () use ($contact, $payment): void {
                Mail::to($contact->email)->locale($contact->locale)->queue(new PaymentReceivedMail($payment));
            });
        }
    }

    private function fail(Payment $payment, ?string $reason, PaymentStatus $as = PaymentStatus::Failed): Payment
    {
        $payment->update([
            'status' => $as,
            'failed_at' => Carbon::now(),
            'failure_reason' => $reason === null ? null : Str::limit($reason, 250, ''),
        ]);

        $this->audit->record('billing.payment_failed', $payment, ['status' => $as->value, 'reason' => $payment->failure_reason]);

        return $payment;
    }

    /**
     * @throws ValidationException
     */
    private function guardPayable(Invoice $invoice): void
    {
        if (! $invoice->isOpen() || $invoice->total_amount <= 0) {
            throw ValidationException::withMessages(['invoice' => __('This invoice is not waiting for payment.')]);
        }

        // Its credit was worked out for the day it was issued.
        if ($invoice->kind === InvoiceKind::Change && $invoice->isOverdue()) {
            throw ValidationException::withMessages([
                'invoice' => __('This offer to change plan has expired. Choose the plan again to get a current invoice.'),
            ]);
        }
    }
}
