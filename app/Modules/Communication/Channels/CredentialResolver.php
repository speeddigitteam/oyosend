<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels;

use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ProviderAccount;

/**
 * Turns a workspace's `credential_ref` into a secret. A reference names either an entry of
 * `config('communication.credentials')` (the secret lives in the environment) or a provider account the operator
 * added in the Super Admin panel (`acct_{id}`, secret encrypted in the database, ADR 0013). A workspace can only
 * name what the operator made available to it, and a platform account only answers to the provider it was
 * created for, so its secret can never be sent to another provider's endpoint.
 */
final class CredentialResolver
{
    public const string PATTERN = '/^[a-z0-9_]{1,60}$/';

    public function __construct(private readonly TenantContext $context) {}

    public function resolve(?string $reference, ?string $provider = null): ?ChannelCredentials
    {
        if (($account = $this->account($reference, $provider)) !== null) {
            $secret = $account->secrets['secret'] ?? null;

            return is_string($secret) && $secret !== '' ? new ChannelCredentials($secret, $account->settings ?? []) : null;
        }

        $entry = $this->entry($reference);
        $secret = $entry['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? new ChannelCredentials($secret) : null;
    }

    /**
     * A second secret on the same entry (the webhook subscription `verify_token`), under the same workspace
     * restriction. Only the listed attributes can be read this way.
     */
    public function attribute(?string $reference, string $key, ?string $provider = null): ?ChannelCredentials
    {
        if (! in_array($key, ['verify_token'], true)) {
            return null;
        }

        if (($account = $this->account($reference, $provider)) !== null) {
            $value = $account->secrets[$key] ?? null;

            return is_string($value) && $value !== '' ? new ChannelCredentials($value) : null;
        }

        $value = $this->entry($reference)[$key] ?? null;

        return is_string($value) && $value !== '' ? new ChannelCredentials($value) : null;
    }

    /**
     * The secret that signs a provider's webhooks. For an environment entry that is its `secret`; a platform
     * account keeps it apart from the API credential (`webhook_secret`).
     */
    public function webhookSecret(?string $reference, ?string $provider = null): ?ChannelCredentials
    {
        if (($account = $this->account($reference, $provider)) !== null) {
            $value = $account->secrets['webhook_secret'] ?? null;

            return is_string($value) && $value !== '' ? new ChannelCredentials($value) : null;
        }

        // A platform account reference that does not answer (another provider, disabled, not for this
        // workspace) must never fall through to the environment lookup.
        if ($reference !== null && ProviderAccount::idFromReference($reference) !== null) {
            return null;
        }

        return $this->resolve($reference);
    }

    /** Whether the reference names a non-empty secret this workspace may use. */
    public function isAvailable(?string $reference): bool
    {
        return $this->resolve($reference) !== null;
    }

    /**
     * Whether the reference may be used with this provider of this channel: an environment entry may be used
     * with any provider; a platform account only with the provider it was created for.
     */
    public function isUsableWith(string $reference, Channel $channel, string $provider): bool
    {
        if (ProviderAccount::idFromReference($reference) === null) {
            return $this->isAvailable($reference);
        }

        $account = $this->account($reference, $provider);

        return $account !== null && $account->channel === $channel;
    }

    /**
     * Reference names this workspace may choose from (names only, never values).
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [];

        foreach (array_keys((array) config('communication.credentials', [])) as $name) {
            if (is_string($name) && $this->isAvailable($name)) {
                $names[] = $name;
            }
        }

        foreach ($this->accounts() as $account) {
            $names[] = $account->reference();
        }

        sort($names);

        return $names;
    }

    /**
     * Platform accounts this workspace may pick, as `reference => label`. Names and labels only.
     *
     * @return array<string, string>
     */
    public function accountLabels(): array
    {
        $labels = [];

        foreach ($this->accounts() as $account) {
            $labels[$account->reference()] = $account->name;
        }

        return $labels;
    }

    /** @return list<ProviderAccount> */
    private function accounts(): array
    {
        $tenantId = (string) $this->context->require()->getKey();

        return ProviderAccount::query()->where('is_enabled', true)->orderBy('name')->get()
            ->filter(static fn (ProviderAccount $account): bool => $account->isAvailableTo($tenantId)
                && is_string($account->secrets['secret'] ?? null) && $account->secrets['secret'] !== '')
            ->values()
            ->all();
    }

    private function account(?string $reference, ?string $provider): ?ProviderAccount
    {
        $id = $reference === null ? null : ProviderAccount::idFromReference($reference);

        if ($id === null) {
            return null;
        }

        $account = ProviderAccount::query()->find($id);

        if ($account === null || ! $account->isAvailableTo((string) $this->context->require()->getKey())) {
            return null;
        }

        return $provider === null || $account->provider === $provider ? $account : null;
    }

    /** @return array{secret?: mixed, tenants?: mixed, verify_token?: mixed}|null */
    private function entry(?string $reference): ?array
    {
        if ($reference === null || preg_match(self::PATTERN, $reference) !== 1) {
            return null;
        }

        $entry = config('communication.credentials.'.$reference);

        if (! is_array($entry)) {
            return null;
        }

        $tenants = $entry['tenants'] ?? null;

        if ($tenants !== null && (! is_array($tenants) || ! in_array($this->context->require()->slug, $tenants, true))) {
            return null;
        }

        return $entry;
    }
}
