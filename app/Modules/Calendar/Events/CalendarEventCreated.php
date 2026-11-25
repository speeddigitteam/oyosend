<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Calendar\Models\CalendarEvent;

/**
 * An event was added to a workspace calendar. Only workspace calendars announce events: a personal calendar is
 * private by person, and a workflow (which runs as whoever published it) must not read it.
 */
final class CalendarEventCreated extends TenantDomainEvent
{
    public readonly string $eventId;

    public readonly string $calendarId;

    public readonly string $title;

    public readonly string $startsAt;

    public readonly string $endsAt;

    public readonly bool $isAllDay;

    public readonly bool $isRecurring;

    public readonly ?string $createdBy;

    public function __construct(CalendarEvent $event)
    {
        parent::__construct();
        $this->eventId = $event->id;
        $this->calendarId = $event->calendar_id;
        $this->title = $event->title;
        $this->startsAt = $event->is_all_day ? $event->starts_at->format('Y-m-d') : $event->starts_at->toIso8601ZuluString();
        $this->endsAt = $event->is_all_day ? $event->ends_at->format('Y-m-d') : $event->ends_at->toIso8601ZuluString();
        $this->isAllDay = $event->is_all_day;
        $this->isRecurring = $event->recurrence !== null;
        $this->createdBy = $event->created_by;
    }

    public static function name(): string
    {
        return 'calendar.event.created';
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'calendar_id' => $this->calendarId,
            'title' => $this->title,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'is_all_day' => $this->isAllDay,
            'is_recurring' => $this->isRecurring,
            'created_by' => $this->createdBy,
        ];
    }
}
