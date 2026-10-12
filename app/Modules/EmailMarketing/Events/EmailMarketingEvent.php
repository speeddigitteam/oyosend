<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Events;

use App\Core\Events\TenantDomainEvent;

/**
 * Shared shape of the Email Marketing domain events: ids and scalars only, dispatched after commit.
 * Payloads never carry email addresses, names or message content.
 */
abstract class EmailMarketingEvent extends TenantDomainEvent
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
