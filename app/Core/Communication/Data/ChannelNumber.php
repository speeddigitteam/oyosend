<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/**
 * One business number of the workspace on a channel (WhatsApp: a phone number of the Business Account).
 *
 * `id` is the platform's own identifier: pass it as `MarketingMessage::$senderNumberId` and read it back from
 * `ChannelEvent::$numberId`. `accountId` is the provider's id of the number (the Graph `phone_number_id`); it
 * is not a secret, but an app should not need it.
 */
final readonly class ChannelNumber
{
    public function __construct(
        public string $id,
        public MarketingChannel $channel,
        public string $accountId,
        public ?string $displayNumber,
        public string $label,
        public bool $isDefault,
        public bool $isActive,
    ) {}
}
