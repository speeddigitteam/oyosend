<?php

declare(strict_types=1);

namespace App\Core\Billing\Gateways;

use App\Core\Billing\Enums\PaymentStatus;

/**
 * A provider's own answer about one payment, obtained server to server.
 */
final readonly class VerifiedPayment
{
    /**
     * @param  int|null  $amount  What was paid, in minor units of `$currency`. Required for a success.
     * @param  string|null  $gatewayReference  The provider's transaction id.
     * @param  string|null  $method  How it was paid: `VISA`, `BKASH`, ...
     * @param  array<string, mixed>  $details  The parts of the answer worth keeping. Never card data or credentials.
     */
    public function __construct(
        public PaymentStatus $status,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $gatewayReference = null,
        public ?string $method = null,
        public ?string $failureReason = null,
        public array $details = [],
    ) {}
}
