<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\CRM\Events\ActivityCompleted;

final class ActivityCompletedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'crm.activity.completed';
    }

    public static function label(): string
    {
        return 'automation.triggers.crm.activity.completed';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function event(): string
    {
        return ActivityCompleted::class;
    }

    public static function requiredPermission(): string
    {
        return 'crm.activities.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['activity_id', 'subject'], 'properties' => ['activity_id' => ['type' => 'string'], 'subject' => ['type' => 'string']]];
    }
}
