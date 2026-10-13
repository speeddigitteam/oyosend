<?php

declare(strict_types=1);

namespace App\Core\Billing\Gateways;

use App\Core\Billing\Models\PaymentGatewayAccount;
use Throwable;

/**
 * Resolves a payment gateway's credentials. An operator sets them in the Super Admin panel
 * (stored encrypted and write-only in `payment_gateway_accounts`); the environment is the
 * fallback default (docs/adr/0028-operator-managed-integration-credentials.md).
 *
 * A saved, enabled account replaces the environment for that gateway. A saved but disabled
 * account yields no secrets, so the gateway reports itself not configured and is not offered.
 * With no saved account — or if the table cannot be read, as during bootstrap or a migration —
 * the environment defaults in `config/platform.php` are used. Secrets are decrypted only here and
 * are never cached across requests, logged or returned to a page.
 */
final class GatewayCredentials
{
    /**
     * Fallback config keys per gateway: the secret fields and the sandbox flag.
     *
     * @var array<string, array{secrets: array<string, string>, sandbox: string}>
     */
    private const array FALLBACK = [
        'sslcommerz' => [
            'secrets' => [
                'store_id' => 'platform.gateways.sslcommerz.store_id',
                'store_password' => 'platform.gateways.sslcommerz.store_password',
            ],
            'sandbox' => 'platform.gateways.sslcommerz.sandbox',
        ],
        'aamarpay' => [
            'secrets' => [
                'store_id' => 'platform.gateways.aamarpay.store_id',
                'signature_key' => 'platform.gateways.aamarpay.signature_key',
            ],
            'sandbox' => 'platform.gateways.aamarpay.sandbox',
        ],
    ];

    /** @var array<string, PaymentGatewayAccount>|null per-request memo of saved accounts */
    private ?array $accounts = null;

    /**
     * The credentials a gateway should use now.
     *
     * @return array{sandbox: bool, secrets: array<string, string>}
     */
    public function for(string $gateway): array
    {
        $account = $this->accounts()[$gateway] ?? null;

        if ($account !== null) {
            return [
                'sandbox' => (bool) ($account->settings['sandbox'] ?? true),
                // A disabled account offers nothing, so the gateway is not configured.
                'secrets' => $account->is_enabled ? array_filter(
                    $account->secrets ?? [],
                    static fn (string $value): bool => $value !== '',
                ) : [],
            ];
        }

        return $this->fromEnvironment($gateway);
    }

    /**
     * @return array{sandbox: bool, secrets: array<string, string>}
     */
    private function fromEnvironment(string $gateway): array
    {
        $map = self::FALLBACK[$gateway] ?? ['secrets' => [], 'sandbox' => ''];

        $secrets = [];
        foreach ($map['secrets'] as $field => $configKey) {
            $value = config($configKey);
            if (is_string($value) && $value !== '') {
                $secrets[$field] = $value;
            }
        }

        return [
            'sandbox' => $map['sandbox'] === '' ? true : (bool) config($map['sandbox'], true),
            'secrets' => $secrets,
        ];
    }

    /**
     * @return array<string, PaymentGatewayAccount>
     */
    private function accounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        try {
            return $this->accounts = PaymentGatewayAccount::query()->get()->keyBy('gateway')->all();
        } catch (Throwable) {
            // The table does not exist yet (bootstrap, a fresh install mid-migration) or the database
            // is unreachable: fall back to the environment rather than failing to build the gateways.
            return $this->accounts = [];
        }
    }
}
