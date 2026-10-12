<?php

declare(strict_types=1);

namespace App\Modules\Communication\Testing;

use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\Contracts\SmsChannel;
use App\Modules\Communication\Channels\Contracts\WhatsAppChannel;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\ProviderEvent;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Channels\HmacSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Enums\ProviderEventType;
use App\Modules\Communication\Models\ChannelConnection;

/**
 * A test double that "sends" into memory. Registered ONLY when the application environment is `testing` or
 * `local`, and flagged `isTestDouble()` so no screen presents it as a real integration.
 *
 * Behaviour is driven by the message body: `FAIL_PERMANENT` is rejected, `FAIL_TEMPORARY` is retried.
 * Webhooks are HMAC-SHA256 of the raw body in `x-signature`, JSON `{"events": [{id, type, message_id, address, text}]}`.
 */
final class InMemoryProvider implements SmsChannel, WhatsAppChannel
{
    public const string KEY_SMS = 'fake_sms';

    public const string KEY_WHATSAPP = 'fake_whatsapp';

    /** @var list<OutboundPayload> */
    public static array $sent = [];

    private function __construct(private readonly Channel $channel) {}

    public static function sms(): ChannelProvider&SmsChannel
    {
        return new self(Channel::Sms);
    }

    public static function whatsapp(): ChannelProvider&WhatsAppChannel
    {
        return new self(Channel::Whatsapp);
    }

    public static function reset(): void
    {
        self::$sent = [];
    }

    public function key(): string
    {
        return $this->channel === Channel::Sms ? self::KEY_SMS : self::KEY_WHATSAPP;
    }

    public function label(): string
    {
        return 'Test provider (local and testing only)';
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function isTestDouble(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [['key' => 'sender_id', 'label' => 'Sender id', 'type' => 'text', 'required' => false, 'maxLength' => 40]];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function state(ChannelConnection $connection): ConfigurationState
    {
        return ConfigurationState::Configured;
    }

    public function send(OutboundPayload $payload, ChannelConnection $connection, ?ChannelCredentials $credentials): SendResult
    {
        if (str_contains($payload->body, 'FAIL_PERMANENT')) {
            return SendResult::rejected('invalid_recipient', 'Rejected by the test provider.');
        }

        if (str_contains($payload->body, 'FAIL_TEMPORARY')) {
            return SendResult::retry('provider_unavailable', 'Temporary failure.');
        }

        self::$sent[] = $payload;

        return SendResult::accepted('fake-'.$payload->messageId);
    }

    public function parseWebhook(string $body, array $headers, string $secret): array
    {
        HmacSignature::assertValid($body, $headers['x-signature'] ?? null, $secret);

        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! is_array($decoded['events'] ?? null)) {
            throw new InvalidWebhookSignature('Unreadable webhook body.');
        }

        $events = [];

        foreach ($decoded['events'] as $event) {
            $type = is_array($event) ? ProviderEventType::tryFrom((string) ($event['type'] ?? '')) : null;

            if ($type === null || ! isset($event['id'])) {
                continue;
            }

            $events[] = new ProviderEvent(
                eventId: (string) $event['id'],
                type: $type,
                providerMessageId: isset($event['message_id']) ? (string) $event['message_id'] : null,
                address: isset($event['address']) ? (string) $event['address'] : null,
                text: isset($event['text']) ? (string) $event['text'] : null,
            );
        }

        return $events;
    }
}
