<?php

declare(strict_types=1);

namespace App\Core\Billing\Gateways;

/**
 * What a gateway is told when a payment is opened. Amount, currency and our
 * reference are on the payment itself.
 */
final readonly class PaymentRequest
{
    /**
     * @param  string  $description  What is being paid for, e.g. "Invoice INV-2026-000042".
     * @param  string  $returnUrl  Where the provider sends the payer's browser afterwards, whatever the outcome.
     * @param  string  $notifyUrl  Where the provider notifies our server.
     */
    public function __construct(
        public string $description,
        public string $customerName,
        public string $customerEmail,
        public ?string $customerPhone,
        public ?string $address,
        public ?string $city,
        public ?string $postalCode,
        public ?string $country,
        public string $returnUrl,
        public string $notifyUrl,
    ) {}
}
