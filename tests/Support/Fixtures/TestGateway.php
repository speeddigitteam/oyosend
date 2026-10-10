<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Core\Billing\Contracts\PaymentGateway;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Gateways\PaymentRedirect;
use App\Core\Billing\Gateways\PaymentRequest;
use App\Core\Billing\Gateways\VerifiedPayment;
use App\Core\Billing\Models\Payment;

/**
 * A payment gateway for tests: it never leaves the process, and the test
 * decides what the "provider" answers when asked to verify a payment.
 *
 * Register it with `useTestGateway()` (tests/Support/InteractsWithBilling).
 */
final class TestGateway implements PaymentGateway
{
    public bool $configured = true;

    /** @var list<string> */
    public array $currencies = ['BDT', 'USD'];

    /** When set, `initiate()` fails with it. */
    public ?GatewayException $initiationFailure = null;

    /** When set, `verify()` fails with it, as an unreachable provider would. */
    public ?GatewayException $verificationFailure = null;

    /** What `verify()` answers. Null: success for exactly what the payment asks for. */
    public ?VerifiedPayment $answer = null;

    /** @var list<PaymentRequest> every request `initiate()` received */
    public array $requests = [];

    /** @var list<array<string, mixed>> every payload `verify()` received */
    public array $verifications = [];

    public function key(): string
    {
        return 'testpay';
    }

    public function name(): string
    {
        return 'TestPay';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, $this->currencies, true);
    }

    public function initiate(Payment $payment, PaymentRequest $request): PaymentRedirect
    {
        if ($this->initiationFailure !== null) {
            throw $this->initiationFailure;
        }

        $this->requests[] = $request;

        return new PaymentRedirect('https://pay.test/checkout/'.$payment->reference, ['session' => 'sess-'.$payment->reference]);
    }

    public function verify(Payment $payment, array $payload): VerifiedPayment
    {
        $this->verifications[] = $payload;

        if ($this->verificationFailure !== null) {
            throw $this->verificationFailure;
        }

        return $this->answer ?? new VerifiedPayment(
            PaymentStatus::Succeeded,
            $payment->amount,
            $payment->currency,
            'txn-'.$payment->reference,
            'CARD',
        );
    }

    public function referenceFrom(array $payload): ?string
    {
        $reference = $payload['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /** The provider will say the payment failed. */
    public function willFail(string $reason = 'Declined by the bank.'): void
    {
        $this->answer = new VerifiedPayment(PaymentStatus::Failed, failureReason: $reason);
    }

    /** The provider will say the payer gave up. */
    public function willCancel(): void
    {
        $this->answer = new VerifiedPayment(PaymentStatus::Canceled);
    }

    /** The provider will confirm a payment of some other amount or currency. */
    public function willConfirm(int $amount, string $currency, string $gatewayReference = 'txn-other'): void
    {
        $this->answer = new VerifiedPayment(PaymentStatus::Succeeded, $amount, $currency, $gatewayReference, 'CARD');
    }
}
