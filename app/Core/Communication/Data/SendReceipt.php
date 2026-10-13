<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use Carbon\CarbonImmutable;

/**
 * What `ChannelGateway::send()` answers.
 *
 * `messageId` is the communication message id; it is null only for `Unavailable`. Store it on your recipient
 * row: status events and `ChannelGateway::statusFor()` use it.
 */
final readonly class SendReceipt
{
    /**
     * @param  string|null  $reason  Why it is `Blocked` (`suppressed`, `no_consent`, `unsubscribed`, `opted_out`),
     *                               `Failed` (`limit_reached`) or `Unavailable`.
     * @param  bool  $replayed  True when the idempotency key was already used and the original message is returned.
     * @param  CarbonImmutable|null  $dueAt  When delivery is due (a future `sendAt` or the end of quiet hours).
     * @param  string|null  $senderNumberId  The workspace number the message was queued from (`ChannelNumber::$id`),
     *                                       WhatsApp only; null when the channel has no numbers.
     */
    public function __construct(
        public DeliveryState $state,
        public ?string $messageId = null,
        public ?string $reason = null,
        public bool $replayed = false,
        public ?CarbonImmutable $dueAt = null,
        public ?string $senderNumberId = null,
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(DeliveryState::Unavailable, null, $reason);
    }

    /** Whether a message row exists and will (or did) move through the outbox. */
    public function isRecorded(): bool
    {
        return $this->messageId !== null;
    }
}
