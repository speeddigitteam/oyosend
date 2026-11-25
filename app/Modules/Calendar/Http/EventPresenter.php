<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Calendar\Access\CalendarAccess;
use App\Modules\Calendar\Enums\ShowAs;
use App\Modules\Calendar\Models\Attendee;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Support\Occurrence;

/**
 * Turns occurrences into what the page draws, in the viewer's own time zone, holding back what they may not read:
 * a busy-only occurrence carries its times and nothing else (no title, place, notes or guests).
 */
final readonly class EventPresenter
{
    public function __construct(private CalendarAccess $access) {}

    /**
     * @param  list<Occurrence>  $occurrences
     * @return list<array<string, mixed>>
     */
    public function present(array $occurrences, User $user, TenantMembership $me, string $zone): array
    {
        $rows = [];
        $editable = [];

        foreach ($occurrences as $occurrence) {
            $root = $occurrence->root;
            $calendar = $root->calendar;
            $guest = $this->guestRow($root, $me);
            $detail = $this->access->eventDetail($me, $root, $calendar, $guest !== null);

            if ($detail === null || ($detail === CalendarAccess::BUSY && $occurrence->event->show_as === ShowAs::Free)) {
                continue;
            }

            $editable[$calendar->id] ??= $this->access->canEdit($user, $me, $calendar);
            $rows[] = $this->row($occurrence, $calendar, $detail === CalendarAccess::FULL, $editable[$calendar->id], $guest, $zone);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(Occurrence $occurrence, Calendar $calendar, bool $full, bool $editable, ?Attendee $guest, string $zone): array
    {
        $event = $occurrence->event;
        $root = $occurrence->root;
        $allDay = $event->is_all_day;

        $base = [
            'key' => $occurrence->key(),
            'id' => $event->id,
            'seriesId' => $root->isRecurring() ? $root->id : null,
            'occurrence' => $occurrence->originalStart?->toIso8601ZuluString(),
            'calendarId' => $calendar->id,
            'color' => ($event->color ?? $calendar->color)->value,
            'allDay' => $allDay,
            'start' => $allDay ? $occurrence->start->format('Y-m-d') : $occurrence->start->setTimezone($zone)->format('Y-m-d\TH:i'),
            'end' => $allDay ? $occurrence->end->format('Y-m-d') : $occurrence->end->setTimezone($zone)->format('Y-m-d\TH:i'),
            'busy' => ! $full,
        ];

        if (! $full) {
            return [...$base, 'title' => null, 'editable' => false, 'recurring' => false];
        }

        return [
            ...$base,
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'timezone' => $event->timezone,
            'eventColor' => $event->color?->value,
            'showAs' => $event->show_as->value,
            'visibility' => $event->visibility->value,
            'reminders' => array_map(intval(...), $event->reminders ?? []),
            'recurring' => $root->isRecurring(),
            'recurrence' => $root->recurrence,
            'organizer' => $this->organizer($root, $calendar),
            'guests' => $root->attendees
                ->map(fn (Attendee $attendee): array => [
                    'id' => $attendee->membership_id,
                    'name' => $attendee->membership->user->name,
                    'response' => $attendee->response->value,
                ])->sortBy('name')->values()->all(),
            'myResponse' => $guest?->response->value,
            'editable' => $editable,
        ];
    }

    private function guestRow(CalendarEvent $root, TenantMembership $me): ?Attendee
    {
        return $root->attendees->first(fn (Attendee $attendee): bool => $attendee->membership_id === $me->id);
    }

    /** @return array{id: string, name: string}|null */
    private function organizer(CalendarEvent $root, Calendar $calendar): ?array
    {
        $member = $calendar->owner ?? $root->creator;

        return $member === null ? null : ['id' => $member->id, 'name' => $member->user->name];
    }
}
