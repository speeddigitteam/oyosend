<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base class for domain events. It captures the active tenant and the moment
 * the event was created, and is only dispatched once the surrounding database
 * transaction commits.
 */
abstract class TenantDomainEvent implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    private readonly string $tenantId;

    private readonly CarbonImmutable $occurredAt;

    public function __construct()
    {
        $this->tenantId = app(TenantContext::class)->requireId();
        $this->occurredAt = CarbonImmutable::now();
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }
}
