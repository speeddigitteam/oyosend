<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Support;

use App\Modules\Calendar\Enums\CalendarView;
use Carbon\CarbonImmutable;

/**
 * The dates a view shows around an anchor date, as local dates [start, end). Pure; mirrored by `rangeFor()` in
 * `resources/js/Modules/Calendar/lib/dates.ts`, and both are tested with the same cases.
 */
final readonly class CalendarRange
{
    /** Days the schedule (agenda) view lists from its anchor. */
    public const int SCHEDULE_DAYS = 31;

    public function __construct(public string $start, public string $end) {}

    public static function for(CalendarView $view, string $anchor, int $weekStartsOn): self
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $anchor, 'UTC');
        $weekStart = static fn (CarbonImmutable $day): CarbonImmutable => $day->subDays(((int) $day->format('w') - $weekStartsOn + 7) % 7);

        [$start, $end] = match ($view) {
            CalendarView::Day => [$date, $date->addDay()],
            CalendarView::FourDays => [$date, $date->addDays(4)],
            CalendarView::Week => [$weekStart($date), $weekStart($date)->addDays(7)],
            CalendarView::Month => [
                $weekStart($date->startOfMonth()),
                $weekStart($date->endOfMonth()->startOfDay())->addDays(7),
            ],
            CalendarView::Year => [$date->startOfYear(), $date->startOfYear()->addYear()],
            CalendarView::Schedule => [$date, $date->addDays(self::SCHEDULE_DAYS)],
        };

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    /** The first instant of the range in a zone. */
    public function startsAt(string $zone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->start, $zone)->utc();
    }

    /** The instant the range ends (exclusive) in a zone. */
    public function endsAt(string $zone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->end, $zone)->utc();
    }
}
