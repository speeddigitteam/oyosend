<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\Automation\Contracts\ActionDefinition;

final class EmitAction implements ActionDefinition
{
    public static function key(): string
    {
        return 'test_flow.item.emit';
    }

    public static function label(): string
    {
        return 'automation.actions.test_flow.item.emit';
    }

    public static function module(): string
    {
        return 'test_flow';
    }

    public static function requiredPermission(): ?string
    {
        return 'test_flow.items.manage';
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
        if (isset(FlowRecorder::$receipts[$idempotencyKey])) {
            return FlowRecorder::$receipts[$idempotencyKey];
        }

        FlowRecorder::$calls[] = ['action' => self::key(), 'input' => $input, 'key' => $idempotencyKey];
        ItemCreated::dispatch((string) ($input['item_id'] ?? 'emitted'), 5, 'emitted');

        return FlowRecorder::$receipts[$idempotencyKey] = ['recorded' => count(FlowRecorder::$calls)];
    }
}
