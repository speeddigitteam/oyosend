<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Data;

use App\Modules\Communication\Enums\Channel;

/**
 * What a provider is asked to send. Built by the dispatcher from a stored message; carries no secret.
 */
final readonly class OutboundPayload
{
    /**
     * @param  string  $messageId  Our message id, usable as the provider's client reference.
     * @param  string  $to  Normalized address: lower-case email or `+digits`.
     * @param  string|null  $unsubscribeUrl  Present for marketing messages only.
     * @param  array<string, scalar|null>  $meta  Provider hints (an approved WhatsApp template name, a sender id).
     * @param  string|null  $senderAccountId  The provider account of the workspace number to send from (WhatsApp
     *                                        `phone_number_id`); null means the connection's own setting.
     * @param  string|null  $senderNumberId  Our id of that number (for adapters that keep per-number state).
     */
    public function __construct(
        public string $messageId,
        public Channel $channel,
        public string $to,
        public ?string $subject,
        public string $body,
        public string $locale,
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public ?string $footer = null,
        public ?string $unsubscribeUrl = null,
        public array $meta = [],
        public ?string $senderAccountId = null,
        public ?string $senderNumberId = null,
    ) {}
}
