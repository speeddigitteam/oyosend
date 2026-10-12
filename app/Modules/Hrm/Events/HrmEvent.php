<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Events;

use App\Core\Events\TenantDomainEvent;

/** Shared shape of the HRM domain events: a flat array of ids and scalars. */
abstract class HrmEvent extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public function payload(): array
    {
        return $this->data;
    }
}
