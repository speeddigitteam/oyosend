<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Channels\ProviderRegistry;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\AccountClaim;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which provider a workspace uses per channel, with its non-secret settings and a credential REFERENCE.
 * Nothing here stores or returns a secret.
 */
final class ChannelConnections
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly CredentialResolver $credentials,
        private readonly AuditLogger $audit,
        private readonly ChannelNumbers $numbers,
    ) {}

    public function find(Channel $channel): ?ChannelConnection
    {
        return ChannelConnection::query()->where('channel', $channel->value)->first();
    }

    /**
     * The connection and adapter the outbox uses. Without a saved connection a channel falls back to the
     * platform mail service (email) or to the honest "no provider" adapter (SMS, WhatsApp).
     *
     * @return array{connection: ChannelConnection, provider: ChannelProvider, saved: bool}
     */
    public function resolve(Channel $channel): array
    {
        $connection = $this->find($channel);
        $provider = $connection === null ? null : $this->providers->find($channel, $connection->provider);

        if ($connection === null || $provider === null) {
            $provider = $this->providers->fallback($channel);
            $connection = new ChannelConnection(['channel' => $channel, 'provider' => $provider->key(), 'is_enabled' => true]);

            return ['connection' => $connection, 'provider' => $provider, 'saved' => false];
        }

        return ['connection' => $connection, 'provider' => $provider, 'saved' => true];
    }

    public function state(Channel $channel): ConfigurationState
    {
        ['connection' => $connection, 'provider' => $provider] = $this->resolve($channel);

        if (! $connection->is_enabled) {
            return ConfigurationState::NotConfigured;
        }

        return $this->stateOf($connection, $provider);
    }

    /**
     * Everything the settings screen needs for one channel. Never contains a secret value.
     *
     * @return array<string, mixed>
     */
    public function describe(Channel $channel): array
    {
        ['connection' => $connection, 'provider' => $provider, 'saved' => $saved] = $this->resolve($channel);

        return [
            'channel' => $channel->value,
            'provider' => $provider->key(),
            'providerLabel' => $provider->label(),
            'isTestDouble' => $provider->isTestDouble(),
            'enabled' => $connection->is_enabled,
            'saved' => $saved,
            'state' => $this->state($channel)->value,
            'settings' => $connection->settings ?? [],
            'credentialRef' => $connection->credential_ref,
            'webhookSecretRef' => $connection->webhook_secret_ref,
            'supportsWebhooks' => $provider->supportsWebhooks(),
            'rateLimitPerMinute' => $connection->rate_limit_per_minute,
            'rateLimitCeiling' => $this->ceiling($channel),
            'providers' => array_map(static fn (ChannelProvider $candidate): array => [
                'key' => $candidate->key(),
                'label' => $candidate->label(),
                'isTestDouble' => $candidate->isTestDouble(),
                'supportsWebhooks' => $candidate->supportsWebhooks(),
                'fields' => $candidate->fields(),
            ], $this->providers->forChannel($channel)),
        ];
    }

    /**
     * Save a channel's provider, non-secret settings and credential references.
     *
     * @param  array<string, mixed>  $input  provider, is_enabled, settings, credential_ref, webhook_secret_ref, rate_limit_per_minute
     */
    public function save(Channel $channel, array $input): ChannelConnection
    {
        $provider = $this->providers->find($channel, (string) ($input['provider'] ?? ''))
            ?? throw ValidationException::withMessages(['provider' => __('Choose one of the available providers.')]);

        $settings = $this->settingsFor($provider, (array) ($input['settings'] ?? []));
        $credentialRef = $this->reference($input['credential_ref'] ?? null, 'credential_ref', $channel, $provider->key());
        $webhookRef = $this->reference($input['webhook_secret_ref'] ?? null, 'webhook_secret_ref', $channel, $provider->key());
        $rate = $input['rate_limit_per_minute'] ?? null;

        if ($rate !== null && ((int) $rate < 1 || (int) $rate > $this->ceiling($channel))) {
            throw ValidationException::withMessages(['rate_limit_per_minute' => __('Enter a limit between 1 and :max messages per minute.', ['max' => $this->ceiling($channel)])]);
        }

        return DB::transaction(function () use ($channel, $provider, $input, $settings, $credentialRef, $webhookRef, $rate): ChannelConnection {
            $connection = ChannelConnection::query()->where('channel', $channel->value)->first() ?? new ChannelConnection;
            $connection->fill([
                'channel' => $channel,
                'provider' => $provider->key(),
                'is_enabled' => (bool) ($input['is_enabled'] ?? true),
                'settings' => $settings === [] ? null : $settings,
                'credential_ref' => $credentialRef,
                'webhook_secret_ref' => $webhookRef,
                'rate_limit_per_minute' => $rate === null ? null : (int) $rate,
            ])->save();

            $this->claimAccounts($connection, $provider, $settings);

            // The phone number id names the default number (ADR 0022): keep the numbers table in step.
            $this->numbers->ensure($connection);

            $this->audit->record('communication.channel.saved', $connection, [
                'channel' => $channel->value,
                'provider' => $provider->key(),
                'enabled' => $connection->is_enabled,
            ]);

            return $connection;
        }, 3); // a deadlock between two saves is retried, not shown to the person
    }

    /**
     * A provider account (a WhatsApp phone number id) is usable by one workspace only. A shared access-token
     * reference is available to every workspace its operator allows, so without this a workspace could name
     * another workspace's number and send from it. The claim is a platform-wide unique index
     * (`communication_account_claims`): the first workspace to save the account holds it, and a second one is
     * refused. Nothing is read across workspaces: the database's own uniqueness answers.
     *
     * @param  array<string, scalar>  $settings
     */
    private function claimAccounts(ChannelConnection $connection, ChannelProvider $provider, array $settings): void
    {
        // Serialise saves of one connection on its own row, and skip the DELETE when it holds no claim: a
        // DELETE over an empty index range takes a gap lock that two concurrent first saves turn into a deadlock.
        ChannelConnection::query()->whereKey($connection->id)->lockForUpdate()->value('id');

        // Claims that belong to a registered number (`number_id`) are the numbers' own (ChannelNumbers).
        if (AccountClaim::query()->where('connection_id', $connection->id)->whereNull('number_id')->exists()) {
            AccountClaim::query()->where('connection_id', $connection->id)->whereNull('number_id')->delete();
        }

        foreach ($provider->fields() as $field) {
            $value = $settings[$field['key']] ?? null;

            if (($field['account'] ?? false) !== true || $value === null || $value === '') {
                continue;
            }

            // A number of this connection already holds this claim.
            if (AccountClaim::query()->where('connection_id', $connection->id)->where('provider', $provider->key())
                ->where('account_key', $field['key'])->where('account_value', (string) $value)->exists()) {
                continue;
            }

            try {
                DB::transaction(static fn () => AccountClaim::query()->create([
                    'connection_id' => $connection->id,
                    'provider' => $provider->key(),
                    'account_key' => $field['key'],
                    'account_value' => (string) $value,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['settings.'.$field['key'] => __('This account is already connected to another workspace.')]);
            }
        }
    }

    /** The most messages per minute a workspace can set for the channel (the platform's value). */
    public function ceiling(Channel $channel): int
    {
        return max(1, (int) config('communication.rate_limits.'.$channel->value, 60));
    }

    /** The per-minute limit that applies to the workspace on a channel. */
    public function rateLimit(Channel $channel): int
    {
        $own = $this->find($channel)?->rate_limit_per_minute;

        return $own === null ? $this->ceiling($channel) : min($own, $this->ceiling($channel));
    }

    public function stateOf(ChannelConnection $connection, ChannelProvider $provider): ConfigurationState
    {
        if ($provider->state($connection) === ConfigurationState::NotConfigured) {
            return ConfigurationState::NotConfigured;
        }

        // A provider that needs no secret (platform mail, a test double) is configured on its own;
        // one with a reference needs the reference to resolve for this workspace.
        if ($connection->credential_ref !== null
            && ! $this->credentials->isUsableWith($connection->credential_ref, $connection->channel, $connection->provider)) {
            return ConfigurationState::NotConfigured;
        }

        return ConfigurationState::Configured;
    }

    /**
     * Keep only the fields the provider declares, validated by type and length.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, scalar>
     */
    private function settingsFor(ChannelProvider $provider, array $input): array
    {
        $clean = [];

        foreach ($provider->fields() as $field) {
            $value = $input[$field['key']] ?? null;

            if ($value === null || $value === '') {
                if ($field['required']) {
                    throw ValidationException::withMessages(['settings.'.$field['key'] => __('This field is required.')]);
                }

                continue;
            }

            $valid = match ($field['type']) {
                'integer' => is_numeric($value) && (int) $value == $value,
                'select' => is_string($value) && in_array($value, $field['options'] ?? [], true),
                default => is_string($value) && mb_strlen($value) <= ($field['maxLength'] ?? 120),
            };

            if (! $valid) {
                throw ValidationException::withMessages(['settings.'.$field['key'] => __('This value is not valid.')]);
            }

            $clean[$field['key']] = $field['type'] === 'integer' ? (int) $value : (string) $value;
        }

        return $clean;
    }

    private function reference(mixed $value, string $field, Channel $channel, string $provider): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A platform account answers only to the provider it was created for (CredentialResolver).
        if (! is_string($value) || ! in_array($value, $this->credentials->available(), true)
            || ! $this->credentials->isUsableWith($value, $channel, $provider)) {
            throw ValidationException::withMessages([$field => __('Choose one of the credential references your operator made available.')]);
        }

        return $value;
    }
}
