<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\NotificationMessage;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Calendar\Access\CalendarAccess;
use App\Modules\Calendar\CalendarServiceProvider;
use App\Modules\Calendar\Enums\AttendeeResponse;
use App\Modules\Calendar\Enums\CalendarVisibility;
use App\Modules\Calendar\Enums\EditScope;
use App\Modules\Calendar\Enums\EventStatus;
use App\Modules\Calendar\Enums\EventVisibility;
use App\Modules\Calendar\Enums\RecurrenceFrequency;
use App\Modules\Calendar\Events\CalendarEventCreated;
use App\Modules\Calendar\Models\Attendee;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Support\EventChanges;
use App\Modules\Calendar\Support\RecurrenceExpander;
use App\Modules\Calendar\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to an event goes through here: single events, whole series, one occurrence ("this event"), and the
 * split of a series at an occurrence ("this and following"), the way people expect from a calendar.
 *
 * Each change is audited inside its transaction. The audit label is the title only where the workspace may read the
 * event anyway (a workspace calendar, or a team-visible one and the event not private); otherwise it says
 * "Private event", so the audit log never leaks a private calendar.
 */
final readonly class EventManager
{
    /** Guests on one event, at most. */
    public const int MAX_GUESTS = 100;

    public function __construct(
        private AuditLogger $audit,
        private RecurrenceExpander $expander,
        private Notifier $notifier,
        private CalendarAccess $access,
    ) {}

    public function create(Calendar $calendar, EventChanges $changes, TenantMembership $actor): CalendarEvent
    {
        return DB::transaction(function () use ($calendar, $changes, $actor): CalendarEvent {
            $event = new CalendarEvent($changes->attributes);
            $event->calendar_id = $calendar->id;
            $event->created_by = $actor->id;
            $this->applyRecurrence($event, $changes->recurrence);
            $event->save();
            $event->setRelation('calendar', $calendar);

            $invited = $changes->attendees === null ? [] : $this->syncAttendees($event, $changes->attendees);
            $this->record('calendar.event.created', $event, ['all_day' => $event->is_all_day, 'recurring' => $event->recurrence !== null]);

            if ($calendar->isShared()) {
                CalendarEventCreated::dispatch($event);
            }

            $this->invite($event, $invited);

            return $event;
        });
    }

    /**
     * Change an event. For a repeating one, `$occurrence` (UTC start of the occurrence the person acted on) and the
     * scope decide what changes. Returns the row that now holds the change.
     */
    public function update(CalendarEvent $event, EventChanges $changes, EditScope $scope, ?CarbonImmutable $occurrence): CalendarEvent
    {
        return DB::transaction(function () use ($event, $changes, $scope, $occurrence): CalendarEvent {
            if ($event->isException()) {
                if ($scope === EditScope::This) {
                    return $this->changeException($event, $changes);
                }

                $occurrence = $event->original_start;
                $event = $event->loadMissing('series')->series ?? abort(404);
            }

            if (! $event->isRecurring()) {
                return $this->changeSeries($event, $changes, null);
            }

            if ($occurrence === null) {
                return $this->changeSeries($event, $changes, null);
            }

            $this->ensureOccurrence($event, $occurrence);

            return match ($scope) {
                EditScope::This => $this->changeException($this->exceptionFor($event, $occurrence), $changes->forOccurrence()),
                EditScope::All => $this->changeSeries($event, $changes, $occurrence),
                EditScope::Following => $this->split($event, $changes, $occurrence),
            };
        });
    }

    public function delete(CalendarEvent $event, EditScope $scope, ?CarbonImmutable $occurrence): void
    {
        DB::transaction(function () use ($event, $scope, $occurrence): void {
            if ($event->isException()) {
                if ($scope === EditScope::This) {
                    $event->status = EventStatus::Cancelled;
                    $event->save();
                    $this->record('calendar.event.deleted', $event, ['scope' => 'this']);

                    return;
                }

                $occurrence = $event->original_start;
                $event = $event->loadMissing('series')->series ?? abort(404);
            }

            if (! $event->isRecurring() || $occurrence === null || $scope === EditScope::All) {
                $this->record('calendar.event.deleted', $event, ['scope' => 'all']);
                $event->delete();

                return;
            }

            $this->ensureOccurrence($event, $occurrence);

            if ($scope === EditScope::This) {
                $exception = $this->exceptionFor($event, $occurrence);
                $exception->status = EventStatus::Cancelled;
                $exception->save();
                $this->record('calendar.event.deleted', $event, ['scope' => 'this']);

                return;
            }

            if (! $this->truncate($event, $occurrence)) {
                $this->record('calendar.event.deleted', $event, ['scope' => 'all']);
                $event->delete();

                return;
            }

            $this->record('calendar.event.deleted', $event, ['scope' => 'following']);
        });
    }

    public function respond(CalendarEvent $root, TenantMembership $guest, AttendeeResponse $response): void
    {
        DB::transaction(function () use ($root, $guest, $response): void {
            $attendee = Attendee::query()->where('event_id', $root->id)->where('membership_id', $guest->id)->lockForUpdate()->first() ?? abort(404);

            if ($attendee->response === $response) {
                return;
            }

            $attendee->response = $response;
            $attendee->responded_at = CarbonImmutable::now();
            $attendee->save();
            $this->record('calendar.event.responded', $root, ['response' => $response->value]);

            $organizer = $this->organizerId($root);

            if ($organizer !== null && $organizer !== $guest->id && $response !== AttendeeResponse::NeedsAction) {
                $name = $guest->loadMissing('user')->user->name;
                $title = $root->title;
                $date = $this->localDate($root);
                $key = match ($response) {
                    AttendeeResponse::Accepted => ':name accepted :title',
                    AttendeeResponse::Tentative => ':name might attend :title',
                    default => ':name declined :title',
                };

                DB::afterCommit(fn () => $this->notifier->notify([$organizer], new NotificationMessage(
                    category: 'calendar.responses',
                    titleKey: $key,
                    params: ['name' => $name, 'title' => $title],
                    linkRoute: 'calendar.index',
                    linkParams: ['view' => 'day', 'date' => $date],
                    dedupeKey: 'cal-rsvp:'.$root->id.':'.$guest->id.':'.$response->value.':'.$attendee->responded_at->getTimestamp(),
                    sourceModule: CalendarServiceProvider::MODULE,
                )));
            }
        });
    }

    /** The member who organises an event: the owner of a personal calendar, else whoever created it. */
    public function organizerId(CalendarEvent $root): ?string
    {
        return $root->loadMissing('calendar')->calendar->owner_id ?? $root->created_by;
    }

    private function changeException(CalendarEvent $exception, EventChanges $changes): CalendarEvent
    {
        $attributes = $changes->attributes;
        unset($attributes['calendar_id']);
        $exception->fill($attributes);
        $exception->status = EventStatus::Confirmed;

        if ($exception->isDirty()) {
            $changed = array_keys($exception->getDirty());
            $exception->save();
            $this->record('calendar.event.updated', $exception, ['scope' => 'this', 'changed' => $changed]);
        }

        return $exception;
    }

    /** Change a single event, or a whole series as seen from one of its occurrences. */
    private function changeSeries(CalendarEvent $event, EventChanges $changes, ?CarbonImmutable $occurrence): CalendarEvent
    {
        $attributes = $changes->attributes;
        $rule = $changes->setsRecurrence ? $changes->recurrence : $event->rule();

        if ($occurrence !== null && isset($attributes['starts_at'], $attributes['ends_at'])) {
            // Move the series by as many days as the occurrence moved, and give it the occurrence's new time of day.
            [$attributes['starts_at'], $attributes['ends_at'], $dayShift] = $this->shiftSeries($event, $occurrence, $attributes);

            if (! $changes->setsRecurrence) {
                $rule = $this->shiftWeekdays($rule, $dayShift);
            }
        }

        $calendarChanged = isset($attributes['calendar_id']) && $attributes['calendar_id'] !== $event->calendar_id;
        $event->fill($attributes);
        $this->applyRecurrence($event, $rule);

        if ($calendarChanged) {
            $event->unsetRelation('calendar');
        }

        $changed = array_keys($event->getDirty());

        if ($changed !== []) {
            $event->save();
        }

        if ($calendarChanged) {
            CalendarEvent::query()->where('recurring_event_id', $event->id)->update(['calendar_id' => $event->calendar_id]);
        }

        $invited = $changes->attendees === null ? [] : $this->syncAttendees($event, $changes->attendees);

        if ($changed !== [] || $invited !== []) {
            $this->record('calendar.event.updated', $event, ['scope' => 'all', 'changed' => $changed]);
        }

        $this->invite($event, $invited);

        return $event;
    }

    /** End the series before the occurrence and start a new one there with the changes ("this and following"). */
    private function split(CalendarEvent $root, EventChanges $changes, CarbonImmutable $occurrence): CalendarEvent
    {
        $rule = $root->rule() ?? abort(404);
        $before = $this->expander->countBefore($this->localStart($root), $rule, $occurrence);

        if ($before === 0) {
            return $this->changeSeries($root, $changes, $occurrence);
        }

        $duration = (int) $root->starts_at->diffInSeconds($root->ends_at);
        $remaining = $rule->count === null ? $rule : $rule->withCount(max(1, $rule->count - $before));
        $this->truncate($root, $occurrence);

        $next = $root->replicate(['recurrence', 'recurrence_until']);
        $next->starts_at = $occurrence;
        $next->ends_at = $occurrence->addSeconds($duration);
        $attributes = $changes->attributes;
        $nextRule = $changes->setsRecurrence ? $changes->recurrence : $remaining;

        if (isset($attributes['starts_at'], $attributes['ends_at']) && ! $changes->setsRecurrence) {
            $dayShift = $this->dayShift($occurrence, $root->wallZone(), $attributes['starts_at'], ($attributes['is_all_day'] ?? $root->is_all_day) ? 'UTC' : (string) ($attributes['timezone'] ?? $root->timezone));
            $nextRule = $this->shiftWeekdays($nextRule, $dayShift);
        }

        $next->fill($attributes);
        $this->applyRecurrence($next, $nextRule);
        $next->save();

        foreach ($root->attendees()->get() as $attendee) {
            Attendee::query()->create([
                'event_id' => $next->id,
                'membership_id' => $attendee->membership_id,
                'response' => $attendee->response,
                'responded_at' => $attendee->responded_at,
            ]);
        }

        $invited = $changes->attendees === null ? [] : $this->syncAttendees($next, $changes->attendees);
        $this->record('calendar.event.updated', $root, ['scope' => 'following', 'series' => $next->id]);
        $this->invite($next, $invited);

        return $next;
    }

    /** Stop the series before the occurrence. False when nothing would be left (the occurrence is the first). */
    private function truncate(CalendarEvent $root, CarbonImmutable $occurrence): bool
    {
        $rule = $root->rule() ?? abort(404);
        $before = $this->expander->countBefore($this->localStart($root), $rule, $occurrence);

        if ($before === 0) {
            return false;
        }

        $truncated = $rule->count !== null
            ? $rule->withCount($before)
            : $rule->endingOn($occurrence->setTimezone($root->wallZone())->subDay()->format('Y-m-d'));

        $this->applyRecurrence($root, $truncated);
        $root->save();
        CalendarEvent::query()->where('recurring_event_id', $root->id)->where('original_start', '>=', $occurrence)->delete();

        return true;
    }

    private function exceptionFor(CalendarEvent $root, CarbonImmutable $occurrence): CalendarEvent
    {
        $existing = CalendarEvent::query()->where('recurring_event_id', $root->id)->where('original_start', $occurrence)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $exception = new CalendarEvent([
            'calendar_id' => $root->calendar_id,
            'title' => $root->title,
            'description' => $root->description,
            'location' => $root->location,
            'is_all_day' => $root->is_all_day,
            'starts_at' => $occurrence,
            'ends_at' => $occurrence->addSeconds((int) $root->starts_at->diffInSeconds($root->ends_at)),
            'timezone' => $root->timezone,
            'color' => $root->color,
            'show_as' => $root->show_as,
            'visibility' => $root->visibility,
            'reminders' => $root->reminders,
        ]);
        $exception->recurring_event_id = $root->id;
        $exception->original_start = $occurrence;
        $exception->created_by = $root->created_by;
        $exception->setRelation('series', $root);
        $exception->setRelation('calendar', $root->loadMissing('calendar')->calendar);

        return $exception;
    }

    private function ensureOccurrence(CalendarEvent $root, CarbonImmutable $occurrence): void
    {
        $rule = $root->rule();
        $known = $rule !== null && $this->expander->occursAt($this->localStart($root), $rule, $occurrence);

        if (! $known && ! CalendarEvent::query()->where('recurring_event_id', $root->id)->where('original_start', $occurrence)->exists()) {
            throw ValidationException::withMessages(['occurrence' => __('That date is not part of the series.')]);
        }
    }

    private function applyRecurrence(CalendarEvent $event, ?RecurrenceRule $rule): void
    {
        if ($rule === null) {
            $event->recurrence = null;
            $event->recurrence_until = null;

            return;
        }

        $local = $this->localStart($event);

        if ($rule->frequency === RecurrenceFrequency::Weekly) {
            $weekdays = array_values(array_unique([...$rule->weekdays, (int) $local->format('w')]));
            sort($weekdays);
            $rule = new RecurrenceRule($rule->frequency, $rule->interval, $weekdays, $rule->monthly, $rule->count, $rule->until);
        }

        if ($rule->until !== null && $rule->until < $local->format('Y-m-d')) {
            throw ValidationException::withMessages(['recurrence.until' => __('A repeating event cannot end before it starts.')]);
        }

        $event->recurrence = $rule->toArray();
        $event->recurrence_until = $this->expander->lastStart($local, $rule)?->utc();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    private function shiftSeries(CalendarEvent $root, CarbonImmutable $occurrence, array $attributes): array
    {
        /** @var CarbonImmutable $newStart */
        $newStart = $attributes['starts_at'];
        /** @var CarbonImmutable $newEnd */
        $newEnd = $attributes['ends_at'];
        $allDay = (bool) ($attributes['is_all_day'] ?? $root->is_all_day);
        $zone = $allDay ? 'UTC' : (string) ($attributes['timezone'] ?? $root->timezone);
        $dayShift = $this->dayShift($occurrence, $root->wallZone(), $newStart, $zone);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $root->starts_at->setTimezone($root->wallZone())->format('Y-m-d'), 'UTC')
            ->addDays($dayShift)->format('Y-m-d');
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$newStart->setTimezone($zone)->format('H:i:s'), $zone)->utc();

        return [$start, $start->addSeconds((int) $newStart->diffInSeconds($newEnd)), $dayShift];
    }

    private function dayShift(CarbonImmutable $from, string $fromZone, CarbonImmutable $to, string $toZone): int
    {
        $a = CarbonImmutable::createFromFormat('!Y-m-d', $from->setTimezone($fromZone)->format('Y-m-d'), 'UTC');
        $b = CarbonImmutable::createFromFormat('!Y-m-d', $to->setTimezone($toZone)->format('Y-m-d'), 'UTC');

        return (int) $a->diffInDays($b, false);
    }

    private function shiftWeekdays(?RecurrenceRule $rule, int $dayShift): ?RecurrenceRule
    {
        if ($rule === null || $rule->frequency !== RecurrenceFrequency::Weekly || $dayShift % 7 === 0) {
            return $rule;
        }

        $weekdays = array_map(static fn (int $day): int => (($day + $dayShift) % 7 + 7) % 7, $rule->weekdays);
        sort($weekdays);

        return new RecurrenceRule($rule->frequency, $rule->interval, array_values(array_unique($weekdays)), $rule->monthly, $rule->count, $rule->until);
    }

    /**
     * Make the guest list exactly the given active members (never the organizer); returns those newly invited.
     *
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    private function syncAttendees(CalendarEvent $event, array $membershipIds): array
    {
        $organizer = $this->organizerId($event);
        $wanted = $this->access->activeMemberIds(array_slice(array_values(array_unique(array_diff($membershipIds, [$organizer]))), 0, self::MAX_GUESTS));
        $existing = Attendee::query()->where('event_id', $event->id)->pluck('membership_id')->all();

        Attendee::query()->where('event_id', $event->id)->whereNotIn('membership_id', $wanted === [] ? [''] : $wanted)->delete();
        $new = array_values(array_diff($wanted, $existing));

        foreach ($new as $membershipId) {
            Attendee::query()->create(['event_id' => $event->id, 'membership_id' => $membershipId]);
        }

        $event->unsetRelation('attendees');

        return $new;
    }

    /** @param list<string> $membershipIds */
    private function invite(CalendarEvent $event, array $membershipIds): void
    {
        if ($membershipIds === []) {
            return;
        }

        $title = $event->title;
        $date = $this->localDate($event);
        $id = $event->id;

        DB::afterCommit(fn () => $this->notifier->notify($membershipIds, new NotificationMessage(
            category: 'calendar.invitations',
            titleKey: 'You are invited to :title',
            params: ['title' => $title],
            linkRoute: 'calendar.index',
            linkParams: ['view' => 'day', 'date' => $date],
            dedupeKey: 'cal-inv:'.$id,
            sourceModule: CalendarServiceProvider::MODULE,
        )));
    }

    /** @param array<string, mixed> $properties */
    private function record(string $name, CalendarEvent $event, array $properties): void
    {
        $calendar = $event->loadMissing('calendar')->calendar;
        $readable = $calendar->isShared()
            || ($calendar->visibility === CalendarVisibility::Team && $event->visibility !== EventVisibility::Private);

        $this->audit->record($name, $event, $properties, null, $readable ? str($event->title)->limit(80)->toString() : 'Private event');
    }

    private function localStart(CalendarEvent $event): CarbonImmutable
    {
        return $event->starts_at->setTimezone($event->wallZone());
    }

    private function localDate(CalendarEvent $event): string
    {
        return $this->localStart($event)->format('Y-m-d');
    }
}
