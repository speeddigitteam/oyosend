<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\Contracts\EmailChannel;
use App\Modules\Communication\Channels\Contracts\SmsChannel;
use App\Modules\Communication\Channels\Contracts\WhatsAppChannel;
use App\Modules\Communication\Channels\Providers\NullProvider;
use App\Modules\Communication\Enums\Channel;
use InvalidArgumentException;

/**
 * Every provider adapter this build knows. Adapters are first-party classes registered in code (never from
 * tenant input); a workspace only chooses among them.
 */
final class ProviderRegistry
{
    /** @var array<string, ChannelProvider> keyed `{channel}:{key}` */
    private array $providers = [];

    public function register(ChannelProvider $provider): void
    {
        $channel = $provider->channel();
        $contract = match ($channel) {
            Channel::Email => EmailChannel::class,
            Channel::Sms => SmsChannel::class,
            Channel::Whatsapp => WhatsAppChannel::class,
        };

        if (! $provider instanceof $contract) {
            throw new InvalidArgumentException('Provider ['.$provider->key().'] must implement '.$contract.'.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $provider->key()) !== 1) {
            throw new InvalidArgumentException('A provider key is lower-case letters, digits and underscores.');
        }

        $id = $channel->value.':'.$provider->key();

        if (isset($this->providers[$id])) {
            throw new InvalidArgumentException("Provider [{$id}] is already registered.");
        }

        $this->providers[$id] = $provider;
    }

    public function find(Channel $channel, string $key): ?ChannelProvider
    {
        return $this->providers[$channel->value.':'.$key] ?? null;
    }

    /** Any channel: used by the webhook route, which names only a provider key. */
    public function findByKey(string $key): ?ChannelProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }

    /** The provider a channel falls back to when a workspace has chosen none. */
    public function fallback(Channel $channel): ChannelProvider
    {
        return match ($channel) {
            Channel::Email => $this->find($channel, 'mail'),
            default => $this->find($channel, NullProvider::KEY),
        } ?? throw new InvalidArgumentException('No fallback provider for '.$channel->value.'.');
    }

    /** @return list<ChannelProvider> */
    public function forChannel(Channel $channel): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn (ChannelProvider $provider): bool => $provider->channel() === $channel,
        ));
    }
}
