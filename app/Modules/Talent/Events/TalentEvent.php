<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

use App\Core\Events\TenantDomainEvent;

/**
 * Shared shape of the Talent domain events: a flat array of ids and scalars, dispatched after commit.
 * Payloads never carry free text from reviews, notes or scorecards.
 */
abstract class TalentEvent extends TenantDomainEvent
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
