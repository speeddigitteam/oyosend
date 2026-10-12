<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\Automation\Contracts\ActionDefinition;

final class ScopedAction implements ActionDefinition
{
    public static function key(): string
    {
        return 'test_flow.item.scoped';
    }

    public static function label(): string
    {
        return 'automation.actions.test_flow.item.scoped';
    }

    public static function module(): string
    {
        return 'test_flow';
    }

    public static function requiredPermission(): ?string
    {
        return 'test_flow.items.scoped';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['label' => ['type' => 'string', 'maxLength' => 100], 'item_id' => ['type' => ['string', 'null']]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['recorded' => ['type' => 'integer']]];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        FlowRecorder::$calls[] = ['action' => self::key(), 'input' => $input, 'key' => $idempotencyKey];

        return ['recorded' => count(FlowRecorder::$calls)];
    }
}
