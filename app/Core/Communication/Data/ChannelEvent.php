<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use App\Core\Communication\Contracts\ChannelEventListener;
use Carbon\CarbonImmutable;

/**
 * One verified provider event, handed to {@see ChannelEventListener}s.
 *
 * It is created only after the webhook's signature was verified and the event id was seen for the first time
 * (per provider), and it is delivered in-process: it is never queued, logged or stored by the platform. The
 * `text` and `details` of an inbound event are untrusted customer input.
 */
final readonly class ChannelEvent
{
    /**
     * @param  string  $provider  The adapter key (`whatsapp_cloud`).
     * @param  string  $eventId  The provider's own id for this event; unique per provider.
     * @param  string|null  $providerMessageId  The provider's id of the message the event is about.
     * @param  string|null  $messageId  Our outbox message id, when the event is about one of our messages.
     * @param  string|null  $address  Normalized sender (inbound) or recipient (status): `+8801...`.
     * @param  string|null  $contactId  The shared contact whose phone/email matches `address`, if any.
     * @param  string|null  $module  Marketing module that queued the message (status events), if any.
     * @param  string|null  $campaignId  That module's campaign id, if any.
     * @param  string|null  $recipientId  That module's recipient/row id, if any.
     * @param  array<string, scalar|null>  $details  Provider facts: `message_type`, `profile_name`, `media_mime`,
     *                                               `pricing_category`, `billable`, template name/language/status.
     */
    public function __construct(
        public MarketingChannel $channel,
        public string $provider,
        public string $eventId,
        public ChannelEventType $type,
        public ?string $providerMessageId = null,
        public ?string $messageId = null,
        public ?string $address = null,
        public ?string $contactId = null,
        public ?string $text = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $module = null,
        public ?string $campaignId = null,
        public ?string $recipientId = null,
        public array $details = [],
    ) {}
}
