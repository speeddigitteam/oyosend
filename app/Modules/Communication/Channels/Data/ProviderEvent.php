<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Data;

use App\Modules\Communication\Enums\ProviderEventType;

/**
 * A verified, normalized provider notification.
 */
final readonly class ProviderEvent
{
    /**
     * @param  string  $eventId  The provider's own event id; processing is idempotent per (provider, id).
     * @param  string|null  $providerMessageId  The id the provider returned when it accepted the message.
     * @param  string|null  $address  Sender (inbound) or affected recipient (bounce, complaint).
     * @param  string|null  $text  Inbound message text.
     */
    public function __construct(
        public string $eventId,
        public ProviderEventType $type,
        public ?string $providerMessageId = null,
        public ?string $address = null,
        public ?string $text = null,
        public ?string $reason = null,
    ) {}
}
