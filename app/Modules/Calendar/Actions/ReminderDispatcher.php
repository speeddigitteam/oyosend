<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Actions;

use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\NotificationMessage;
use App\Modules\Calendar\CalendarServiceProvider;
use App\Modules\Calendar\Enums\AttendeeResponse;
use App\Modules\Calendar\Enums\EventStatus;
use App\Modules\Calendar\Models\Attendee;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Support\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sends the reminders that fell due, for the active workspace. Runs every minute (`calendar.reminders`).
 *
 * A reminder is due when its moment (the start minus its lead; for an all-day event, 9:00 on the day in the event's
 * zone minus the lead) lies in the last few minutes, so a late or skipped run still sends it. The notifier records
 * each (event, occurrence, lead) once per member through its dedupe key, so a run twice over the same minutes sends
 * nothing twice. Reminders go to the organiser and to every guest who has not declined, through the platform's
 * notification centre; with no communication app installed nothing is sent and nothing pretends otherwise.
 */
final readonly class ReminderDispatcher
{
    /** How far back a reminder still counts as due, in minutes. */
    public const int LOOKBACK = 10;

    /** The longest lead a reminder may have, in minutes (four weeks). */
    public const int MAX_LEAD = 40_320;

    /** The time of day an all-day event's reminders count from. */
    public const string ALL_DAY_AT = '09:00:00';

    public function __construct(
        private RecurrenceExpander $expander,
        private Notifier $notifier,
        private EventManager $events,
    ) {}

    public function dispatchDue(CarbonImmutable $now): int
    {
        $now = $now->utc()->startOfMinute()->addMinute()->subSecond();
        $from = $now->subMinutes(self::LOOKBACK);
        $horizon = $now->addMinutes(self::MAX_LEAD)->addDay();
        $sent = 0;

        $rows = CalendarEvent::query()
            ->where('status', EventStatus::Confirmed->value)
            ->whereNotNull('reminders')
            ->where('starts_at', '<=', $horizon)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $single) => $single->whereNull('recurrence')->where('starts_at', '>=', $from->subDay()))
                ->orWhere(fn (Builder $series) => $series->whereNotNull('recurrence')->whereNull('recurring_event_id')->where(fn (Builder $open) => $open
                    ->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', $from->subDay()))))
            ->with(['calendar', 'series.calendar'])
            ->limit(5000)
            ->get();

        $seriesIds = $rows->filter(fn (CalendarEvent $event): bool => $event->isRecurring())->modelKeys();
        $replaced = [];

        if ($seriesIds !== []) {
            CalendarEvent::query()->whereIn('recurring_event_id', $seriesIds)->where('original_start', '>=', $from->subDay())
                ->get(['recurring_event_id', 'original_start'])
                ->each(function (CalendarEvent $exception) use (&$replaced): void {
                    $replaced[$exception->recurring_event_id][$exception->original_start?->getTimestamp()] = true;
                });
        }

        foreach ($rows as $event) {
            $leads = array_values(array_filter(array_map(intval(...), $event->reminders ?? []), static fn (int $lead): bool => $lead >= 0 && $lead <= self::MAX_LEAD));

            if ($leads === []) {
                continue;
            }

            $starts = $event->isRecurring()
                ? $this->occurrences($event, $from, $now->addMinutes(max($leads))->addDay())
                : [$event->starts_at];

            foreach ($starts as $start) {
                if (isset($replaced[$event->id][$start->getTimestamp()])) {
                    continue;
                }

                $anchor = $this->anchor($event, $start);

                foreach ($leads as $lead) {
                    $due = $anchor->subMinutes($lead);

                    if ($due->greaterThan($from) && $due->lessThanOrEqualTo($now)) {
                        $sent += $this->send($event, $start, $lead);
                    }
                }
            }
        }

        return $sent;
    }

    /** @return list<CarbonImmutable> UTC occurrence starts. */
    private function occurrences(CalendarEvent $root, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rule = $root->rule();

        if ($rule === null) {
            return [];
        }

        $local = $root->starts_at->setTimezone($root->wallZone());

        return array_map(
            static fn (CarbonImmutable $start): CarbonImmutable => $start->utc(),
            $this->expander->between($local, $rule, 0, $from->subDay(), $to),
        );
    }

    /** The moment reminders count back from: the start, or 9:00 on the day of an all-day event in its zone. */
    private function anchor(CalendarEvent $event, CarbonImmutable $start): CarbonImmutable
    {
        if (! $event->is_all_day) {
            return $start;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $start->format('Y-m-d').' '.self::ALL_DAY_AT, $event->timezone)->utc();
    }

    private function send(CalendarEvent $event, CarbonImmutable $start, int $lead): int
    {
        $root = $event->isException() ? $event->series : $event;

        if ($root === null) {
            return 0;
        }

        $recipients = Attendee::query()->where('event_id', $root->id)
            ->where('response', '!=', AttendeeResponse::Declined->value)
            ->pluck('membership_id')->all();
        $organizer = $this->events->organizerId($root);

        if ($organizer !== null) {
            $recipients[] = $organizer;
        }

        $recipients = array_values(array_unique($recipients));

        if ($recipients === []) {
            return 0;
        }

        [$bodyKey, $count] = $this->when($event->is_all_day, $lead);
        $date = $event->is_all_day ? $start->format('Y-m-d') : $start->setTimezone($event->timezone)->format('Y-m-d');

        return $this->notifier->notify($recipients, new NotificationMessage(
            category: 'reminders',
            titleKey: 'Reminder: :title',
            params: ['title' => $event->title, 'count' => $count],
            bodyKey: $bodyKey,
            linkRoute: 'calendar.index',
            linkParams: ['view' => 'day', 'date' => $date],
            dedupeKey: 'cal-rem:'.$event->id.':'.$start->getTimestamp().':'.$lead,
            sourceModule: CalendarServiceProvider::MODULE,
        ));
    }

    /** @return array{0: string, 1: int} */
    private function when(bool $allDay, int $lead): array
    {
        if ($allDay) {
            $days = intdiv($lead + 1439, 1440);

            return match (true) {
                $days === 0 => ['Today, all day', 0],
                $days === 1 => ['Tomorrow, all day', 1],
                default => ['In :count days, all day', $days],
            };
        }

        return match (true) {
            $lead === 0 => ['Starting now', 0],
            $lead < 60 => ['Starts in :count minutes', $lead],
            $lead < 1440 => ['Starts in :count hours', intdiv($lead, 60)],
            $lead < 10_080 => ['Starts in :count days', intdiv($lead, 1440)],
            default => ['Starts in :count weeks', intdiv($lead, 10_080)],
        };
    }
}
