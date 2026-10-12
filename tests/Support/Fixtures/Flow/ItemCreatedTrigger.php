<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\Automation\Contracts\TriggerDefinition;

final class ItemCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'test_flow.item.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.test_flow.item.created';
    }

    public static function module(): string
    {
        return 'test_flow';
    }

    public static function event(): ?string
    {
        return ItemCreated::class;
    }

    public static function requiredPermission(): ?string
    {
        return 'test_flow.items.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['kind' => ['type' => ['string', 'null']], 'minimum_amount' => ['type' => 'integer', 'minimum' => 0]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['item_id' => ['type' => 'string'], 'amount' => ['type' => 'integer'], 'kind' => ['type' => 'string']]];
    }
}
