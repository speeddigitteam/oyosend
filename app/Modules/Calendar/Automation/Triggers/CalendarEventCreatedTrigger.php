<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Calendar\Events\CalendarEventCreated;

/** An event is added to a workspace calendar (never a personal one). */
final class CalendarEventCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'calendar.event.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.calendar.event.created';
    }

    public static function module(): string
    {
        return 'calendar';
    }

    public static function event(): string
    {
        return CalendarEventCreated::class;
    }

    public static function requiredPermission(): string
    {
        return 'calendar.events.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['event_id', 'calendar_id', 'title', 'starts_at', 'ends_at'], 'properties' => [
            'event_id' => ['type' => 'string'],
            'calendar_id' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'starts_at' => ['type' => 'string'],
            'ends_at' => ['type' => 'string'],
            'is_all_day' => ['type' => 'boolean'],
            'is_recurring' => ['type' => 'boolean'],
            'created_by' => ['type' => ['string', 'null']],
        ]];
    }
}
