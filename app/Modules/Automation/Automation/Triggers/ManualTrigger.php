<?php

declare(strict_types=1);

namespace App\Modules\Automation\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;

/**
 * A member starts the workflow by hand, optionally with a JSON payload.
 *
 * Raised by the engine itself, so there is no domain event: `event()` is null.
 */
final class ManualTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'automation.trigger.manual';
    }

    public static function label(): string
    {
        return 'automation.triggers.automation.trigger.manual';
    }

    public static function module(): string
    {
        return 'automation';
    }

    public static function event(): ?string
    {
        return null;
    }

    public static function requiredPermission(): string
    {
        return 'automation.runs.start';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => true];
    }
}
