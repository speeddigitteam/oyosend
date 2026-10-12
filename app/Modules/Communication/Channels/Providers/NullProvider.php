<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers;

use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\Contracts\SmsChannel;
use App\Modules\Communication\Channels\Contracts\WhatsAppChannel;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\ChannelConnection;

/**
 * The honest default for a channel with no provider: it is never configured and refuses every send.
 * It is the answer a workspace gets until an operator adds a real adapter (docs/COMMUNICATION.md).
 */
final class NullProvider implements SmsChannel, WhatsAppChannel
{
    public const string KEY = 'none';

    private function __construct(private readonly Channel $channel) {}

    public static function sms(): ChannelProvider&SmsChannel
    {
        return new self(Channel::Sms);
    }

    public static function whatsapp(): ChannelProvider&WhatsAppChannel
    {
        return new self(Channel::Whatsapp);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'No provider';
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function isTestDouble(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function state(ChannelConnection $connection): ConfigurationState
    {
        return ConfigurationState::NotConfigured;
    }

    public function send(OutboundPayload $payload, ChannelConnection $connection, ?ChannelCredentials $credentials): SendResult
    {
        return SendResult::rejected('provider_not_configured', 'No provider is configured for this channel.');
    }

    public function parseWebhook(string $body, array $headers, string $secret): array
    {
        throw new InvalidWebhookSignature('This provider does not receive webhooks.');
    }
}
