<?php

declare(strict_types=1);

namespace App\Modules\Communication\Events;

use App\Core\Events\TenantDomainEvent;

/** A contact withdrew marketing consent on a channel (through the unsubscribe link, by hand or by a complaint). */
final class ContactUnsubscribed extends TenantDomainEvent
{
    public function __construct(
        private readonly ?string $contactId,
        private readonly string $channel,
        private readonly string $method,
    ) {
        parent::__construct();
    }

    public static function name(): string
    {
        return 'communication.contact.unsubscribed';
    }

    public function payload(): array
    {
        return ['contact_id' => $this->contactId, 'channel' => $this->channel, 'method' => $this->method];
    }
}
