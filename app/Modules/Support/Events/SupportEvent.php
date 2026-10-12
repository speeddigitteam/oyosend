<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

use App\Core\Events\TenantDomainEvent;

/**
 * Shared shape of the Support domain events: a flat array of ids and scalars, dispatched after commit.
 * Payloads never carry message text, requester names or email addresses.
 */
abstract class SupportEvent extends TenantDomainEvent
{
    /** @param array<string, scalar|null> $data */
    public function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public function payload(): array
    {
        return $this->data;
    }
}
