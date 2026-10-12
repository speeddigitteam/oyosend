<?php

declare(strict_types=1);

namespace App\Modules\Automation\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;

/**
 * A cron schedule, evaluated in the workspace timezone, starts the workflow.
 *
 * Raised by the engine itself, so there is no domain event: `event()` is null.
 */
final class ScheduleTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'automation.trigger.schedule';
    }

    public static function label(): string
    {
        return 'automation.triggers.automation.trigger.schedule';
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
        return 'automation.workflows.publish';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['cron'], 'properties' => ['cron' => ['type' => 'string', 'minLength' => 9, 'maxLength' => 100]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['scheduled_for'], 'properties' => ['scheduled_for' => ['type' => 'string', 'format' => 'date-time']]];
    }
}
