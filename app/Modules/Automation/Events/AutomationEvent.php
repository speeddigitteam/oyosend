<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

use App\Core\Events\TenantDomainEvent;

/** Common shape of the automation module's domain events: a name and a payload of ids and scalars. */
abstract class AutomationEvent extends TenantDomainEvent
{
    /** @param  array<string, scalar|null>  $data */
    public function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    /** @return array<string, scalar|null> */
    public function payload(): array
    {
        return $this->data;
    }
}
