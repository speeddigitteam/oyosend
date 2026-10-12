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
     * @param  array<string, string>  $accounts  The provider account(s) the event belongs to (`phone_number_id`). The
     *                                           webhook route drops an event whose accounts do not match the
     *                                           connection's own settings, so a shared provider app can never
     *                                           feed one workspace another workspace's events.
     * @param  array<string, scalar|null>  $meta  Provider facts for listeners (message type, profile name, pricing).
     */
    public function __construct(
        public string $eventId,
        public ProviderEventType $type,
        public ?string $providerMessageId = null,
        public ?string $address = null,
        public ?string $text = null,
        public ?string $reason = null,
        public array $accounts = [],
        public array $meta = [],
    ) {}
}
