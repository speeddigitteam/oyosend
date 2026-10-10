<?php

declare(strict_types=1);

namespace App\Core\Billing\Gateways;

use App\Core\Billing\Contracts\PaymentGateway;
use InvalidArgumentException;

/**
 * The payment gateways compiled into this build. Which of them can actually
 * be used depends on their credentials being configured.
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway $gateway): void
    {
        if (isset($this->gateways[$gateway->key()])) {
            throw new InvalidArgumentException("Payment gateway [{$gateway->key()}] is already registered.");
        }

        $this->gateways[$gateway->key()] = $gateway;
    }

    public function find(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * @return array<string, PaymentGateway>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Gateways a payment in this currency can be made through right now.
     *
     * @return list<PaymentGateway>
     */
    public function usable(string $currency): array
    {
        return array_values(array_filter(
            $this->gateways,
            static fn (PaymentGateway $gateway): bool => $gateway->isConfigured() && $gateway->supportsCurrency($currency),
        ));
    }
}
