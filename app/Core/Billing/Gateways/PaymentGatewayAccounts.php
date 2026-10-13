<?php

declare(strict_types=1);

namespace App\Core\Billing\Gateways;

use App\Core\Billing\Models\PaymentGatewayAccount;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Manages the operator-set payment gateway credentials shown in the Super Admin panel
 * (docs/adr/0028-operator-managed-integration-credentials.md). Secrets are written encrypted
 * and are never read back out to a page: the panel only ever learns whether a gateway is
 * configured, its sandbox flag and where its credentials come from.
 */
final class PaymentGatewayAccounts
{
    /**
     * The secret fields each gateway needs. Order is the display order.
     *
     * @var array<string, list<string>>
     */
    public const array FIELDS = [
        'sslcommerz' => ['store_id', 'store_password'],
        'aamarpay' => ['store_id', 'signature_key'],
    ];

    /**
     * Save an operator's credentials for a gateway. Blank secret fields keep the stored value
     * (so the operator can change the sandbox flag without re-typing the password); a field is
     * only cleared by clearing the whole gateway back to the environment.
     *
     * @param  array<string, string>  $secrets
     */
    public function save(string $gateway, array $secrets, bool $sandbox, bool $isEnabled): void
    {
        $fields = self::FIELDS[$gateway] ?? throw new InvalidArgumentException("Unknown payment gateway [{$gateway}].");

        $account = PaymentGatewayAccount::query()->where('gateway', $gateway)->first();
        $existing = $account === null ? [] : ($account->secrets ?? []);

        $merged = [];
        foreach ($fields as $field) {
            $value = trim((string) ($secrets[$field] ?? ''));
            $kept = (string) ($existing[$field] ?? '');
            $resolved = $value !== '' ? $value : $kept;

            if ($resolved !== '') {
                $merged[$field] = $resolved;
            }
        }

        PaymentGatewayAccount::query()->updateOrCreate(
            ['gateway' => $gateway],
            ['secrets' => $merged, 'settings' => ['sandbox' => $sandbox], 'is_enabled' => $isEnabled],
        );
    }

    /**
     * Forget an operator's credentials for a gateway: it falls back to the environment.
     */
    public function clear(string $gateway): void
    {
        PaymentGatewayAccount::query()->where('gateway', $gateway)->delete();
    }

    /**
     * What the panel may show about a gateway. Never includes a secret.
     *
     * @return array{managed: bool, sandbox: bool, enabled: bool, missingFields: list<string>, updatedAt: ?string}
     */
    public function status(string $gateway): array
    {
        $fields = self::FIELDS[$gateway] ?? [];
        $account = PaymentGatewayAccount::query()->firstWhere('gateway', $gateway);

        if ($account === null) {
            return ['managed' => false, 'sandbox' => true, 'enabled' => false, 'missingFields' => $fields, 'updatedAt' => null];
        }

        $held = $account->secrets ?? [];
        $missing = array_values(array_filter(
            $fields,
            static fn (string $field): bool => ! is_string($held[$field] ?? null) || $held[$field] === '',
        ));

        return [
            'managed' => true,
            'sandbox' => (bool) ($account->settings['sandbox'] ?? true),
            'enabled' => $account->is_enabled,
            'missingFields' => $missing,
            'updatedAt' => $account->updated_at instanceof Carbon ? $account->updated_at->toIso8601String() : null,
        ];
    }
}
