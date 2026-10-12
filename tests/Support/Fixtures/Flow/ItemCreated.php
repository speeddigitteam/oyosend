<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\Events\TenantDomainEvent;

final class ItemCreated extends TenantDomainEvent
{
    public function __construct(public readonly string $itemId, public readonly int $amount = 0, public readonly string $kind = 'standard')
    {
        parent::__construct();
    }

    public static function name(): string
    {
        return 'test_flow.item.created';
    }

    public function payload(): array
    {
        return ['item_id' => $this->itemId, 'amount' => $this->amount, 'kind' => $this->kind];
    }
}
