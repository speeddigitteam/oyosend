<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Events;

use App\Core\Events\TenantDomainEvent;

/**
 * Shared shape of the Meta Ads domain events: a flat array of ids and scalars, dispatched after commit.
 * Payloads never carry a token, a provider error body or an amount of money.
 */
abstract class MetaAdsEvent extends TenantDomainEvent
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
