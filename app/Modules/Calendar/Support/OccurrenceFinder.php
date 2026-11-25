<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Support;

use App\Modules\Calendar\Enums\EventStatus;
use App\Modules\Calendar\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Loads what a range of the calendar shows and expands recurring series into occurrences, with their exceptions
 * applied: an exception replaces its occurrence (or removes it, when cancelled) and appears wherever it was moved.
 *
 * The SQL windows are widened by a day on each side because all-day dates float; the exact overlap is decided here.
 */
final readonly class OccurrenceFinder
{
    /** How far before the range a repeating occurrence may start and still reach into it. */
    private const int LOOKBACK_DAYS = 32;

    public function __construct(private RecurrenceExpander $expander) {}

    /**
     * @param  list<string>  $calendarIds  Calendars whose events are shown.
     * @param  list<string>  $guestOf  Event ids (series roots) shown because the viewer is a guest.
     * @return list<Occurrence> In start order.
     */
    public function between(array $calendarIds, array $guestOf, CalendarRange $range, string $zone): array
    {
        if ($calendarIds === [] && $guestOf === []) {
            return [];
        }

        $from = $range->startsAt($zone);
        $to = $range->endsAt($zone);
        $floatingFrom = $range->startsAt('UTC');
        $floatingTo = $range->endsAt('UTC');
        $wideFrom = $from->subDay();
        $wideTo = $to->addDay();
        $roots = $this->reach('id', $calendarIds, $guestOf)
            ->whereNull('recurring_event_id')
            ->where('status', EventStatus::Confirmed->value)
            ->where('starts_at', '<', $wideTo)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $single) => $single->whereNull('recurrence')->where('ends_at', '>', $wideFrom))
                ->orWhere(fn (Builder $series) => $series->whereNotNull('recurrence')->where(fn (Builder $open) => $open
                    ->whereNull('recurrence_until')
                    ->orWhere('recurrence_until', '>=', $wideFrom->subDays(self::LOOKBACK_DAYS)))))
            ->with(['calendar.owner.user:id,name', 'attendees.membership.user:id,name', 'creator.user:id,name'])
            ->limit(2000)
            ->get();

        $exceptions = $this->reach('recurring_event_id', $calendarIds, $guestOf)
            ->whereNotNull('recurring_event_id')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $moved) => $moved->where('starts_at', '<', $wideTo)->where('ends_at', '>', $wideFrom))
                ->orWhere(fn (Builder $replaced) => $replaced
                    ->where('original_start', '<', $wideTo)
                    ->where('original_start', '>=', $wideFrom->subDays(self::LOOKBACK_DAYS))))
            ->with('calendar')
            ->limit(2000)
            ->get();

        $missing = $exceptions->pluck('recurring_event_id')->unique()->diff($roots->modelKeys())->values()->all();
        $series = $roots->keyBy('id');

        if ($missing !== []) {
            CalendarEvent::query()->whereIn('id', $missing)
                ->with(['calendar.owner.user:id,name', 'attendees.membership.user:id,name', 'creator.user:id,name'])
                ->get()
                ->each(fn (CalendarEvent $root) => $series->put($root->id, $root));
        }

        /** @var array<string, array<int, true>> $replaced */
        $replaced = [];

        foreach ($exceptions as $exception) {
            if ($exception->original_start !== null) {
                $replaced[(string) $exception->recurring_event_id][$exception->original_start->getTimestamp()] = true;
            }
        }

        $overlaps = static fn (CalendarEvent $event, CarbonImmutable $start, CarbonImmutable $end): bool => $event->is_all_day
            ? $start->lessThan($floatingTo) && $end->greaterThan($floatingFrom)
            : $start->lessThan($to) && ($end->greaterThan($from) || ($end->equalTo($start) && $start->greaterThanOrEqualTo($from)));

        $occurrences = [];

        foreach ($roots as $root) {
            $rule = $root->rule();

            if ($rule === null) {
                if ($overlaps($root, $root->starts_at, $root->ends_at)) {
                    $occurrences[] = new Occurrence($root, $root, $root->starts_at, $root->ends_at, null);
                }

                continue;
            }

            $duration = (int) $root->starts_at->diffInSeconds($root->ends_at);
            $localStart = $root->starts_at->setTimezone($root->wallZone());
            [$windowFrom, $windowTo] = $root->is_all_day ? [$floatingFrom, $floatingTo] : [$from, $to];

            foreach ($this->expander->between($localStart, $rule, $duration, $windowFrom, $windowTo) as $start) {
                $utc = $start->utc();

                if (isset($replaced[$root->id][$utc->getTimestamp()])) {
                    continue;
                }

                $occurrences[] = new Occurrence($root, $root, $utc, $utc->addSeconds($duration), $utc);
            }
        }

        foreach ($exceptions as $exception) {
            $root = $series->get((string) $exception->recurring_event_id);

            if ($root === null || $exception->status === EventStatus::Cancelled || ! $overlaps($exception, $exception->starts_at, $exception->ends_at)) {
                continue;
            }

            $occurrences[] = new Occurrence($exception, $root, $exception->starts_at, $exception->ends_at, $exception->original_start);
        }

        usort($occurrences, static fn (Occurrence $a, Occurrence $b): int => [$a->start->getTimestamp(), $b->end->getTimestamp() * -1] <=> [$b->start->getTimestamp(), $a->end->getTimestamp() * -1]);

        return $occurrences;
    }

    /**
     * Events on the given calendars, or tied (by `$column`) to an event the viewer is a guest of.
     *
     * @param  list<string>  $calendarIds
     * @param  list<string>  $guestOf
     * @return Builder<CalendarEvent>
     */
    private function reach(string $column, array $calendarIds, array $guestOf): Builder
    {
        return CalendarEvent::query()->where(fn (Builder $scope) => $scope
            ->whereIn('calendar_id', $calendarIds === [] ? [''] : $calendarIds)
            ->orWhereIn($column, $guestOf === [] ? [''] : $guestOf));
    }
}
