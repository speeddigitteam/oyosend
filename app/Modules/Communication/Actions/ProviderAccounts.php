<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Channels\Contracts\PlatformAccountProvider;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\ProviderRegistry;
use App\Modules\Communication\Channels\Providers\HttpSmsProvider;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\ProviderAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The operator's messaging provider accounts (ADR 0013). Settings are validated against the field list the
 * adapter declares; secrets are stored encrypted, are write-only (an empty value on update keeps the stored one)
 * and appear in no return value, audit entry or exception message of this class.
 */
final class ProviderAccounts
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Adapters an account can be created for.
     *
     * @return list<PlatformAccountProvider>
     */
    public function adapters(): array
    {
        $adapters = [];

        foreach (Channel::cases() as $channel) {
            foreach ($this->providers->forChannel($channel) as $provider) {
                if ($provider instanceof PlatformAccountProvider && ! $provider->isTestDouble()) {
                    $adapters[] = $provider;
                }
            }
        }

        return $adapters;
    }

    /**
     * What a page may show about an account: never a secret value, only which secrets are set.
     *
     * @return array<string, mixed>
     */
    public function present(ProviderAccount $account): array
    {
        $adapter = $this->providers->find($account->channel, $account->provider);

        return [
            'id' => $account->id,
            'channel' => $account->channel->value,
            'provider' => $account->provider,
            'providerLabel' => $adapter?->label() ?? $account->provider,
            'name' => $account->name,
            'enabled' => $account->is_enabled,
            'settings' => $account->settings ?? [],
            'secretsSet' => array_keys(array_filter($account->secrets ?? [], static fn (string $value): bool => $value !== '')),
            'tenantIds' => $account->tenant_ids,
            'supportsTest' => $adapter instanceof PlatformAccountProvider && $adapter->supportsTestSend(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  channel, provider, name, is_enabled, settings, secrets, tenant_ids
     */
    public function create(array $input): ProviderAccount
    {
        $adapter = $this->adapter((string) ($input['channel'] ?? ''), (string) ($input['provider'] ?? ''));
        $clean = $this->clean($adapter, $input, null);

        return DB::transaction(function () use ($adapter, $clean, $input): ProviderAccount {
            $account = ProviderAccount::query()->create([
                'channel' => $adapter->channel(),
                'provider' => $adapter->key(),
                'name' => $clean['name'],
                'is_enabled' => (bool) ($input['is_enabled'] ?? true),
                'settings' => $clean['settings'] === [] ? null : $clean['settings'],
                'secrets' => $clean['secrets'] === [] ? null : $clean['secrets'],
                'tenant_ids' => $clean['tenant_ids'],
            ]);

            $this->audit->record('platform.messaging.account_created', null, [
                'account' => $account->id, 'channel' => $account->channel->value, 'provider' => $account->provider, 'name' => $account->name,
            ], subjectLabel: $account->name);

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $input  name, is_enabled, settings, secrets, tenant_ids (channel and provider are fixed)
     */
    public function update(ProviderAccount $account, array $input): ProviderAccount
    {
        $adapter = $this->adapter($account->channel->value, $account->provider);
        $clean = $this->clean($adapter, $input, $account);

        return DB::transaction(function () use ($account, $clean, $input): ProviderAccount {
            $changedSecrets = array_keys(array_filter($clean['secrets'], fn (string $value, string $key): bool => ($account->secrets[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

            $account->fill([
                'name' => $clean['name'],
                'is_enabled' => (bool) ($input['is_enabled'] ?? $account->is_enabled),
                'settings' => $clean['settings'] === [] ? null : $clean['settings'],
                'secrets' => $clean['secrets'] === [] ? null : $clean['secrets'],
                'tenant_ids' => $clean['tenant_ids'],
            ])->save();

            // Names of the secrets that were replaced, never their values.
            $this->audit->record('platform.messaging.account_updated', null, [
                'account' => $account->id, 'channel' => $account->channel->value, 'provider' => $account->provider,
                'name' => $account->name, 'enabled' => $account->is_enabled, 'secrets_replaced' => $changedSecrets,
            ], subjectLabel: $account->name);

            return $account;
        });
    }

    public function delete(ProviderAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $account->delete();

            $this->audit->record('platform.messaging.account_deleted', null, [
                'account' => $account->id, 'channel' => $account->channel->value, 'provider' => $account->provider, 'name' => $account->name,
            ], subjectLabel: $account->name);
        });
    }

    /**
     * Send one message through the account to prove it works. The outcome is a code, never the provider's text.
     */
    public function sendTest(ProviderAccount $account, string $to): SendResult
    {
        $adapter = $this->adapter($account->channel->value, $account->provider);
        $address = $account->channel->normalize($to);

        if (! $adapter->supportsTestSend()) {
            throw ValidationException::withMessages(['to' => __('This provider cannot send a test message.')]);
        }

        if ($address === null) {
            throw ValidationException::withMessages(['to' => $account->channel === Channel::Email
                ? __('Enter a valid email address.')
                : __('Enter a valid phone number with its country code.')]);
        }

        $secret = $account->secrets['secret'] ?? null;
        $credentials = is_string($secret) && $secret !== '' ? new ChannelCredentials($secret, $account->settings ?? []) : null;

        $connection = new ChannelConnection(['channel' => $account->channel, 'provider' => $account->provider, 'is_enabled' => true]);
        $connection->credential_ref = $account->reference();

        $payload = new OutboundPayload(
            messageId: 'test',
            channel: $account->channel,
            to: $address,
            subject: $account->channel->hasSubject() ? 'Oyosend test message' : null,
            body: 'This is a test message from your Oyosend platform. No action is needed.',
            locale: 'en',
        );

        $result = $adapter->send($payload, $connection, $credentials);

        $this->audit->record('platform.messaging.account_tested', null, [
            'account' => $account->id, 'channel' => $account->channel->value, 'outcome' => $result->outcome, 'code' => $result->code,
        ], subjectLabel: $account->name);

        return $result;
    }

    private function adapter(string $channel, string $provider): PlatformAccountProvider
    {
        $channelEnum = Channel::tryFrom($channel);
        $adapter = $channelEnum === null ? null : $this->providers->find($channelEnum, $provider);

        if (! $adapter instanceof PlatformAccountProvider || $adapter->isTestDouble()) {
            throw ValidationException::withMessages(['provider' => __('Choose one of the available providers.')]);
        }

        return $adapter;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, settings: array<string, scalar>, secrets: array<string, string>, tenant_ids: list<string>|null}
     */
    private function clean(PlatformAccountProvider $adapter, array $input, ?ProviderAccount $existing): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => __('Enter a name of up to 80 characters.')]);
        }

        $taken = ProviderAccount::query()
            ->where('channel', $adapter->channel()->value)
            ->where('name', $name)
            ->when($existing !== null, static fn ($query) => $query->whereKeyNot($existing?->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('Another account of this channel already has this name.')]);
        }

        $settingsInput = (array) ($input['settings'] ?? []);
        $secretsInput = (array) ($input['secrets'] ?? []);
        $settings = [];
        $secrets = [];

        foreach ($adapter->accountFields() as $field) {
            if ($field['type'] === 'secret') {
                $value = $secretsInput[$field['key']] ?? null;
                $kept = $existing?->secrets[$field['key']] ?? null;

                if (is_string($value) && $value !== '') {
                    if (mb_strlen($value) > 4096) {
                        throw ValidationException::withMessages(['secrets.'.$field['key'] => __('This value is not valid.')]);
                    }

                    $secrets[$field['key']] = $value;
                } elseif (is_string($kept) && $kept !== '') {
                    $secrets[$field['key']] = $kept;
                } elseif ($field['required']) {
                    throw ValidationException::withMessages(['secrets.'.$field['key'] => __('This field is required.')]);
                }

                continue;
            }

            $value = $settingsInput[$field['key']] ?? null;

            if ($value === null || $value === '') {
                if ($field['required']) {
                    throw ValidationException::withMessages(['settings.'.$field['key'] => __('This field is required.')]);
                }

                continue;
            }

            $valid = match ($field['type']) {
                'integer' => is_numeric($value) && (int) $value == $value && (int) $value > 0 && (int) $value <= 65535,
                'select' => is_string($value) && in_array($value, $field['options'] ?? [], true),
                default => is_string($value) && mb_strlen($value) <= ($field['maxLength'] ?? 190),
            };

            if (! $valid) {
                throw ValidationException::withMessages(['settings.'.$field['key'] => __('This value is not valid.')]);
            }

            $settings[$field['key']] = $field['type'] === 'integer' ? (int) $value : trim((string) $value);
        }

        $this->providerChecks($adapter, $settings);

        return ['name' => $name, 'settings' => $settings, 'secrets' => $secrets, 'tenant_ids' => $this->tenantIds($input['tenant_ids'] ?? null)];
    }

    /** @param array<string, scalar> $settings */
    private function providerChecks(PlatformAccountProvider $adapter, array $settings): void
    {
        $problems = [];

        if ($adapter->key() === 'smtp' && filter_var($settings['from_address'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
            $problems['from_address'] = __('Enter a valid email address.');
        }

        if ($adapter->key() === 'twilio' && preg_match('/^AC[0-9a-fA-F]{32}$/', (string) ($settings['account_sid'] ?? '')) !== 1) {
            $problems['account_sid'] = __('This value is not valid.');
        }

        if ($adapter->key() === HttpSmsProvider::KEY) {
            foreach (HttpSmsProvider::problems($settings) as $key => $message) {
                $problems[$key] = __($message);
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(static fn (string $key): string => 'settings.'.$key, array_keys($problems)),
                array_values($problems),
            ));
        }
    }

    /** @return list<string>|null Null means every workspace. */
    private function tenantIds(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $ids = is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];

        if ($ids === [] || Tenant::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['tenant_ids' => __('Choose at least one existing workspace, or allow all workspaces.')]);
        }

        return $ids;
    }
}
