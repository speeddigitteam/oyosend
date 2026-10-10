<?php

declare(strict_types=1);

namespace App\Core\Billing\Gateways;

/**
 * Where to send the payer, and what the provider said when the payment was opened.
 */
final readonly class PaymentRedirect
{
    /**
     * @param  array<string, mixed>  $details  Kept on the payment (a session key, ...). Never credentials.
     */
    public function __construct(
        public string $url,
        public array $details = [],
    ) {}
}
