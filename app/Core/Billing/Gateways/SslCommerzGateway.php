<?php

declare(strict_types=1);

namespace App\Core\Billing\Gateways;

use App\Core\Billing\Contracts\PaymentGateway;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * SSLCommerz hosted checkout (API v4), which also carries bKash, Nagad, Rocket
 * and cards. https://developer.sslcommerz.com/doc/v4/
 *
 * Written against the published API and covered by tests with recorded
 * responses. NOT yet run against the SSLCommerz sandbox: that needs a store id
 * and password (`SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD`). Until
 * they are set the gateway is not configured and is not offered.
 *
 * A payment is accepted only on the answer of SSLCommerz's own validation
 * API, checked against our reference, amount and currency. What arrives at
 * the return or IPN URL is used for one thing: finding the `val_id` to ask about.
 */
final class SslCommerzGateway implements PaymentGateway
{
    private const string SANDBOX = 'https://sandbox.sslcommerz.com';

    private const string LIVE = 'https://securepay.sslcommerz.com';

    private const int TIMEOUT = 20;

    public function __construct(
        private readonly Http $http,
        private readonly ?string $storeId,
        private readonly ?string $storePassword,
        private readonly bool $sandbox,
    ) {}

    public function key(): string
    {
        return 'sslcommerz';
    }

    public function name(): string
    {
        return 'SSLCommerz';
    }

    public function isConfigured(): bool
    {
        return ($this->storeId ?? '') !== '' && ($this->storePassword ?? '') !== '';
    }

    public function supportsCurrency(string $currency): bool
    {
        // Settlement is in BDT; SSLCommerz converts the others it accepts.
        return in_array(strtoupper($currency), ['BDT', 'USD', 'EUR', 'GBP', 'SGD', 'INR', 'MYR'], true);
    }

    public function initiate(Payment $payment, PaymentRequest $request): PaymentRedirect
    {
        $this->requireConfigured();

        // SSLCommerz refuses a session without these; a made-up value would be a lie on a payment record.
        if ($request->customerPhone === null || $request->address === null || $request->city === null
            || $request->postalCode === null || $request->country === null) {
            throw GatewayException::missingBillingDetails($this->key());
        }

        $response = $this->send(fn (): Response => $this->http->asForm()->timeout(self::TIMEOUT)->post(
            $this->base().'/gwprocess/v4/api.php',
            [
                'store_id' => $this->storeId,
                'store_passwd' => $this->storePassword,
                'total_amount' => Money::decimal($payment->amount, $payment->currency),
                'currency' => $payment->currency,
                'tran_id' => $payment->reference,
                'success_url' => $request->returnUrl,
                'fail_url' => $request->returnUrl,
                'cancel_url' => $request->returnUrl,
                'ipn_url' => $request->notifyUrl,
                'product_name' => mb_substr($request->description, 0, 255),
                'product_category' => 'software subscription',
                'product_profile' => 'non-physical-goods',
                'shipping_method' => 'NO',
                'cus_name' => mb_substr($request->customerName, 0, 50),
                'cus_email' => $request->customerEmail,
                'cus_phone' => $request->customerPhone,
                'cus_add1' => mb_substr($request->address, 0, 50),
                'cus_city' => mb_substr($request->city, 0, 50),
                'cus_postcode' => $request->postalCode,
                'cus_country' => $request->country,
            ],
        ));

        $url = $response->json('GatewayPageURL');

        if ($response->json('status') !== 'SUCCESS' || ! is_string($url) || $url === '') {
            throw new GatewayException('SSLCommerz refused the payment session: '.(string) $response->json('failedreason', 'no reason given'));
        }

        return new PaymentRedirect($url, ['sessionkey' => $response->json('sessionkey')]);
    }

    public function verify(Payment $payment, array $payload): VerifiedPayment
    {
        $this->requireConfigured();

        $valId = $payload['val_id'] ?? null;

        $transaction = is_string($valId) && $valId !== ''
            ? $this->validate($valId)
            : $this->queryByReference($payment->reference);

        if ($transaction === null) {
            return new VerifiedPayment(PaymentStatus::Pending);
        }

        $status = match (strtoupper((string) ($transaction['status'] ?? ''))) {
            'VALID', 'VALIDATED' => PaymentStatus::Succeeded,
            'FAILED', 'EXPIRED', 'UNATTEMPTED' => PaymentStatus::Failed,
            'CANCELLED' => PaymentStatus::Canceled,
            default => PaymentStatus::Pending,
        };

        if ($status !== PaymentStatus::Succeeded) {
            return new VerifiedPayment($status, failureReason: $this->text($transaction['error'] ?? null));
        }

        // An answer about some other transaction proves nothing about this one.
        if (($transaction['tran_id'] ?? null) !== $payment->reference) {
            return new VerifiedPayment(PaymentStatus::Failed, failureReason: 'The gateway answered about a different transaction.');
        }

        // `currency_type` / `currency_amount` are what the payer was charged;
        // `amount` is the same value converted to BDT for settlement.
        $currency = strtoupper((string) ($transaction['currency_type'] ?? $transaction['currency'] ?? ''));
        $amount = Money::minorUnits((string) ($transaction['currency_amount'] ?? $transaction['amount'] ?? ''), $currency);

        $details = array_filter([
            'val_id' => $this->text($transaction['val_id'] ?? null),
            'bank_tran_id' => $this->text($transaction['bank_tran_id'] ?? null),
            'card_type' => $this->text($transaction['card_type'] ?? null),
            'card_brand' => $this->text($transaction['card_brand'] ?? null),
            'store_amount' => $this->text($transaction['store_amount'] ?? null),
            'tran_date' => $this->text($transaction['tran_date'] ?? null),
            'risk_level' => $this->text($transaction['risk_level'] ?? null),
            'risk_title' => $this->text($transaction['risk_title'] ?? null),
        ], static fn (?string $value): bool => $value !== null);

        // SSLCommerz: "hold the service" for a risky transaction. An operator decides.
        if ((string) ($transaction['risk_level'] ?? '0') === '1') {
            return new VerifiedPayment(
                PaymentStatus::Pending,
                $amount,
                $currency,
                $this->text($transaction['bank_tran_id'] ?? null),
                $this->text($transaction['card_type'] ?? null),
                'Held by the gateway for a risk review.',
                $details,
            );
        }

        return new VerifiedPayment(
            PaymentStatus::Succeeded,
            $amount,
            $currency,
            $this->text($transaction['bank_tran_id'] ?? null) ?? $this->text($transaction['val_id'] ?? null),
            $this->text($transaction['card_type'] ?? null),
            null,
            $details,
        );
    }

    public function referenceFrom(array $payload): ?string
    {
        $reference = $payload['tran_id'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * Order Validation API: the transaction a `val_id` stands for.
     *
     * @return array<string, mixed>|null
     */
    private function validate(string $valId): ?array
    {
        $response = $this->send(fn (): Response => $this->http->timeout(self::TIMEOUT)->get(
            $this->base().'/validator/api/validationserverAPI.php',
            [
                'val_id' => $valId,
                'store_id' => $this->storeId,
                'store_passwd' => $this->storePassword,
                'v' => 1,
                'format' => 'json',
            ],
        ));

        $transaction = $response->json();

        return is_array($transaction) && isset($transaction['status']) ? $transaction : null;
    }

    /**
     * Transaction Query API: every attempt made under our reference. A
     * successful attempt wins; otherwise the latest one speaks for the payment.
     *
     * @return array<string, mixed>|null
     */
    private function queryByReference(string $reference): ?array
    {
        $response = $this->send(fn (): Response => $this->http->timeout(self::TIMEOUT)->get(
            $this->base().'/validator/api/merchantTransIDvalidationAPI.php',
            [
                'tran_id' => $reference,
                'store_id' => $this->storeId,
                'store_passwd' => $this->storePassword,
                'format' => 'json',
            ],
        ));

        if ($response->json('APIConnect') !== 'DONE') {
            throw new GatewayException('SSLCommerz did not accept the transaction query: '.(string) $response->json('APIConnect', 'no answer'));
        }

        $attempts = array_values(array_filter((array) $response->json('element', []), is_array(...)));

        if ($attempts === []) {
            return null;
        }

        foreach ($attempts as $attempt) {
            if (in_array(strtoupper((string) ($attempt['status'] ?? '')), ['VALID', 'VALIDATED'], true)) {
                return $attempt;
            }
        }

        return end($attempts) ?: null;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new GatewayException('SSLCommerz could not be reached.', previous: $exception);
        }

        if ($response->failed()) {
            throw new GatewayException("SSLCommerz answered with HTTP {$response->status()}.");
        }

        return $response;
    }

    private function requireConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new GatewayException('SSLCommerz is not configured.');
        }
    }

    private function base(): string
    {
        return $this->sandbox ? self::SANDBOX : self::LIVE;
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
