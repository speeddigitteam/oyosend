<?php

declare(strict_types=1);

namespace App\Modules\Automation\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;

/**
 * An inbound HTTPS call signed with the workflow's secret starts the workflow with the JSON body as its payload.
 *
 * Raised by the engine itself, so there is no domain event: `event()` is null.
 */
final class WebhookTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'automation.trigger.webhook';
    }

    public static function label(): string
    {
        return 'automation.triggers.automation.trigger.webhook';
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
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => true];
    }
}
