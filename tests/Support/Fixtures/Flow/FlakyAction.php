<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\Automation\Contracts\ActionDefinition;
use RuntimeException;

final class FlakyAction implements ActionDefinition
{
    public static function key(): string
    {
        return 'test_flow.item.flaky';
    }

    public static function label(): string
    {
        return 'automation.actions.test_flow.item.flaky';
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

        $left = FlowRecorder::$failures['flaky'] ?? 0;

        if ($left > 0) {
            FlowRecorder::$failures['flaky'] = $left - 1;

            throw new RuntimeException('temporary outage');
        }

        FlowRecorder::$calls[] = ['action' => self::key(), 'input' => $input, 'key' => $idempotencyKey];

        return FlowRecorder::$receipts[$idempotencyKey] = ['recorded' => count(FlowRecorder::$calls)];
    }
}
