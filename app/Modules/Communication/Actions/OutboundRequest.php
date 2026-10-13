<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use Carbon\CarbonInterface;

/**
 * One message to put in the outbox.
 */
final readonly class OutboundRequest
{
    /**
     * @param  string  $recipient  Address as given; it is normalized and validated for the channel.
     * @param  string  $idempotencyKey  Identifies the intent, up to 160 characters. The same key queues once.
     * @param  string|null  $source  Where it came from: `composer`, `automation`, `notification`, a module key.
     * @param  CarbonInterface|null  $sendAt  Not before this moment.
     * @param  array<string, scalar|null>  $meta  Small facts (provider hints), never secrets.
     * @param  string|null  $senderNumberId  The workspace number (communication_channel_numbers id) to send from.
     */
    public function __construct(
        public Channel $channel,
        public MessagePurpose $purpose,
        public string $recipient,
        public ?string $subject,
        public string $body,
        public string $idempotencyKey,
        public ?string $contactId = null,
        public ?string $companyId = null,
        public ?string $membershipId = null,
        public ?string $templateId = null,
        public ?string $locale = null,
        public ?string $source = null,
        public ?CarbonInterface $sendAt = null,
        public array $meta = [],
        public ?string $senderNumberId = null,
    ) {}
}
