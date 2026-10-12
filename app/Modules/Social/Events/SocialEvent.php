<?php

declare(strict_types=1);

namespace App\Modules\Social\Events;

use App\Core\Events\TenantDomainEvent;

/**
 * Shared shape of the Social domain events: a flat array of ids and scalars, dispatched after commit.
 * Payloads never carry post text, tokens or provider error bodies.
 */
abstract class SocialEvent extends TenantDomainEvent
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
