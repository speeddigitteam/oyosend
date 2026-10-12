<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\Automation\Contracts\ActionDefinition;
use RuntimeException;

final class FailAction implements ActionDefinition
{
    public static function key(): string
    {
        return 'test_flow.item.fail';
    }

    public static function label(): string
    {
        return 'automation.actions.test_flow.item.fail';
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
        FlowRecorder::$calls[] = ['action' => self::key(), 'input' => $input, 'key' => $idempotencyKey];

        throw new RuntimeException('boom');
    }
}
