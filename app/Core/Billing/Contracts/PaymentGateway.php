<?php

declare(strict_types=1);

namespace App\Core\Billing\Contracts;

use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Gateways\PaymentRedirect;
use App\Core\Billing\Gateways\PaymentRequest;
use App\Core\Billing\Gateways\VerifiedPayment;
use App\Core\Billing\Models\Payment;

/**
 * A provider through which a workspace can pay an invoice online.
 *
 * The flow is always the same: {@see initiate()} hands the payer to the
 * provider; the provider sends them back and notifies us; {@see verify()}
 * then asks the provider, server to server, what really happened. A payment
 * succeeds only on that answer - never on what the browser or a notification
 * claims.
 *
 * Register implementations with {@see GatewayRegistry}.
 * A gateway that has no credentials reports `isConfigured() === false` and is
 * simply not offered.
 */
interface PaymentGateway
{
    /**
     * Stable identifier, stored on every payment: `sslcommerz`.
     */
    public function key(): string;

    /**
     * The provider's own name, shown as it is: `SSLCommerz`.
     */
    public function name(): string;

    public function isConfigured(): bool;

    public function supportsCurrency(string $currency): bool;

    /**
     * Open a payment at the provider and return where to send the payer.
     *
     * @throws GatewayException
     */
    public function initiate(Payment $payment, PaymentRequest $request): PaymentRedirect;

    /**
     * Ask the provider what became of the payment.
     *
     * `$payload` is what the provider sent to the return or notification URL.
     * It may be used to look the transaction up (an id in it), but nothing in
     * it is to be believed: the result must come from the provider's own
     * validation API.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws GatewayException
     */
    public function verify(Payment $payment, array $payload): VerifiedPayment;

    /**
     * Our payment reference inside a notification from the provider, or null.
     *
     * @param  array<string, mixed>  $payload
     */
    public function referenceFrom(array $payload): ?string;
}
