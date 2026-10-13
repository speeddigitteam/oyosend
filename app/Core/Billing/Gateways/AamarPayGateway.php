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
 * aamarPay hosted checkout (bKash, Nagad, Rocket, cards, banking), BDT only.
 * https://aamarpay.readme.io/
 *
 * Written against the published JSON API and covered by tests with recorded
 * shaped responses. NOT yet run against the aamarPay sandbox: that needs a
 * store id and signature key (`AAMARPAY_STORE_ID`, `AAMARPAY_SIGNATURE_KEY`).
 * Until they are set the gateway is not configured and is not offered.
 *
 * A payment is accepted only on the answer of aamarPay's transaction
 * verification API, asked by our own reference (`mer_txnid`) and checked
 * against that reference, amount and currency. What arrives at the return
 * URL (a cross-site POST of the payer's browser) is never read for an outcome.
 */
final class AamarPayGateway implements PaymentGateway
{
    private const string SANDBOX = 'https://sandbox.aamarpay.com';

    private const string LIVE = 'https://secure.aamarpay.com';

    private const int TIMEOUT = 20;

    public function __construct(
        private readonly Http $http,
        private readonly ?string $storeId,
        private readonly ?string $signatureKey,
        private readonly bool $sandbox,
    ) {}

    public function key(): string
    {
        return 'aamarpay';
    }

    public function name(): string
    {
        return 'aamarPay';
    }

    public function isConfigured(): bool
    {
        return ($this->storeId ?? '') !== '' && ($this->signatureKey ?? '') !== '';
    }

    public function supportsCurrency(string $currency): bool
    {
        return strtoupper($currency) === 'BDT';
    }

    public function initiate(Payment $payment, PaymentRequest $request): PaymentRedirect
    {
        $this->requireConfigured();

        if (! $this->supportsCurrency($payment->currency)) {
            throw new GatewayException('aamarPay accepts BDT only.');
        }

        // aamarPay refuses a session without these; a made-up value would be a lie on a payment record.
        if ($request->customerPhone === null || $request->address === null || $request->city === null
            || $request->postalCode === null || $request->country === null) {
            throw GatewayException::missingBillingDetails($this->key());
        }

        $response = $this->send(fn (): Response => $this->http->asJson()->acceptJson()->timeout(self::TIMEOUT)->post(
            $this->base().'/jsonpost.php',
            [
                'store_id' => $this->storeId,
                'signature_key' => $this->signatureKey,
                'tran_id' => $payment->reference,
                'amount' => Money::decimal($payment->amount, $payment->currency),
                'currency' => 'BDT',
                'desc' => mb_substr($request->description, 0, 255),
                'cus_name' => mb_substr($request->customerName, 0, 50),
                'cus_email' => $request->customerEmail,
                'cus_phone' => $request->customerPhone,
                'cus_add1' => mb_substr($request->address, 0, 50),
                'cus_city' => mb_substr($request->city, 0, 50),
                'cus_postcode' => $request->postalCode,
                'cus_country' => $request->country,
                // The payer comes back to the same place whatever happened; the outcome is asked for, not believed.
                'success_url' => $request->returnUrl,
                'fail_url' => $request->returnUrl,
                'cancel_url' => $request->returnUrl,
                'type' => 'json',
            ],
        ));

        $answer = $response->json();
        $url = is_array($answer) ? ($answer['payment_url'] ?? null) : null;
        $result = is_array($answer) ? ($answer['result'] ?? null) : null;

        if (! is_string($url) || $url === '' || ! in_array($result, [true, 'true', 'True'], true)) {
            // The answer on a refusal is a plain message or a small object; only a short text is kept.
            $reason = is_array($answer) ? ($answer['error'] ?? $answer['message'] ?? $answer['result'] ?? null) : trim((string) $response->body());

            throw new GatewayException('aamarPay refused the payment session: '.($this->text($reason, 200) ?? 'no reason given'));
        }

        return new PaymentRedirect($url);
    }

    public function verify(Payment $payment, array $payload): VerifiedPayment
    {
        $this->requireConfigured();

        // The payload is not read at all: the answer is asked for by our own reference,
        // so it can only be about this payment.
        $response = $this->send(fn (): Response => $this->http->acceptJson()->timeout(self::TIMEOUT)->get(
            $this->base().'/api/v1/trxcheck/request.php',
            [
                'request_id' => $payment->reference,
                'store_id' => $this->storeId,
                'signature_key' => $this->signatureKey,
                'type' => 'json',
            ],
        ));

        $transaction = $response->json();

        // Unknown transaction (no attempt yet) or an answer that is not a transaction: undecided.
        if (! is_array($transaction) || ! isset($transaction['mer_txnid'], $transaction['pay_status'])) {
            return new VerifiedPayment(PaymentStatus::Pending);
        }

        if ($transaction['mer_txnid'] !== $payment->reference) {
            return new VerifiedPayment(PaymentStatus::Failed, failureReason: 'The gateway answered about a different transaction.');
        }

        $status = match (strtolower(trim((string) $transaction['pay_status']))) {
            'successful', 'success' => PaymentStatus::Succeeded,
            'failed', 'fail' => PaymentStatus::Failed,
            'cancelled', 'canceled' => PaymentStatus::Canceled,
            default => PaymentStatus::Pending,
        };

        if ($status !== PaymentStatus::Succeeded) {
            return new VerifiedPayment(
                $status,
                failureReason: $this->text($transaction['status_title'] ?? $transaction['pg_error'] ?? null, 200),
            );
        }

        // `status_code` 2 is "successful" in aamarPay's table. Anything else beside a
        // "Successful" text is a contradiction, and a contradiction is never money.
        if (isset($transaction['status_code']) && (string) $transaction['status_code'] !== '2') {
            return new VerifiedPayment(PaymentStatus::Pending, failureReason: 'aamarPay answered with a status that is not final.');
        }

        $currency = strtoupper((string) ($transaction['currency_merchant'] ?? $transaction['currency'] ?? ''));
        $amount = Money::minorUnits((string) ($transaction['amount'] ?? ''), $currency === '' ? 'BDT' : $currency);

        $details = array_filter([
            'pg_txnid' => $this->text($transaction['pg_txnid'] ?? null, 100),
            'bank_trxid' => $this->text($transaction['bank_trxid'] ?? null, 100),
            'payment_type' => $this->text($transaction['payment_type'] ?? null, 100),
            'payment_processor' => $this->text($transaction['payment_processor'] ?? null, 100),
            'store_amount' => $this->text($transaction['store_amount'] ?? null, 50),
            'date_processed' => $this->text($transaction['date_processed'] ?? $transaction['date'] ?? null, 50),
            'risk_title' => $this->text($transaction['risk_title'] ?? null, 100),
        ], static fn (?string $value): bool => $value !== null);

        return new VerifiedPayment(
            PaymentStatus::Succeeded,
            $amount,
            $currency,
            $this->text($transaction['pg_txnid'] ?? null, 100) ?? $this->text($transaction['bank_trxid'] ?? null, 100),
            $this->text($transaction['payment_type'] ?? $transaction['payment_processor'] ?? null, 100),
            null,
            $details,
        );
    }

    public function referenceFrom(array $payload): ?string
    {
        $reference = $payload['mer_txnid'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new GatewayException('aamarPay could not be reached.', previous: $exception);
        }

        if ($response->failed()) {
            throw new GatewayException("aamarPay answered with HTTP {$response->status()}.");
        }

        return $response;
    }

    private function requireConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new GatewayException('aamarPay is not configured.');
        }
    }

    private function base(): string
    {
        return $this->sandbox ? self::SANDBOX : self::LIVE;
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        $text = mb_substr((string) $value, 0, $limit);

        // Whatever aamarPay says is never allowed to carry our own secrets into a record.
        foreach ([$this->storeId, $this->signatureKey] as $secret) {
            if ($secret !== null && $secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return $text;
    }
}
