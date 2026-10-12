<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Data\ChannelAvailability;
use App\Core\Communication\Data\ChannelState;
use App\Core\Communication\Data\MarketingChannel;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;

/**
 * Whether a channel can send right now, for marketing apps: the app is entitled, the channel is enabled and
 * its provider (and credential reference) is configured. Mirrors the checks the dispatcher makes at send time.
 */
final class MarketingChannelStatus
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly ChannelConnections $connections,
    ) {}

    public static function channel(MarketingChannel $channel): Channel
    {
        return Channel::from($channel->value);
    }

    public function check(MarketingChannel $channel): ChannelAvailability
    {
        if (! $this->access->entitled()) {
            return new ChannelAvailability($channel, ChannelState::NotConfigured, 'module_unavailable');
        }

        ['connection' => $connection, 'provider' => $provider] = $this->connections->resolve(self::channel($channel));
        $double = $provider->isTestDouble();

        if (! $connection->is_enabled) {
            return new ChannelAvailability($channel, ChannelState::NotConfigured, 'channel_disabled', $double);
        }

        if ($this->connections->stateOf($connection, $provider) === ConfigurationState::NotConfigured) {
            return new ChannelAvailability($channel, ChannelState::NotConfigured, 'provider_not_configured', $double);
        }

        return new ChannelAvailability($channel, ChannelState::Available, null, $double);
    }
}
