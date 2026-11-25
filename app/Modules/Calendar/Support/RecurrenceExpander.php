<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Support;

use App\Modules\Calendar\Enums\MonthlyMode;
use App\Modules\Calendar\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;

/**
 * Expands a {@see RecurrenceRule} into occurrence starts. Pure: no database, no clock.
 *
 * The series start is a wall-clock time in the event's own zone, and so is every occurrence: dates are stepped as
 * plain calendar dates and the start's time of day is put back in that zone, so a 9:00 meeting stays at 9:00 across
 * a daylight-saving change while its UTC instant moves. Weeks start on Monday, as in RFC 5545. A date the rule asks
 * for that the month does not have (the 31st, the fifth Friday, 29 February) is skipped, not moved.
 */
final class RecurrenceExpander
{
    /** Candidate dates looked at in one call, at most: a guard, never reached by a real window. */
    private const int MAX_STEPS = 50_000;

    /**
     * The occurrence starts of a series whose occurrences overlap [$from, $to).
     *
     * @return list<CarbonImmutable> In the series' zone, in order.
     */
    public function between(CarbonImmutable $start, RecurrenceRule $rule, int $durationSeconds, CarbonInterface $from, CarbonInterface $to): array
    {
        $occurrences = [];
        $earliest = CarbonImmutable::instance($from)->subSeconds(max(0, $durationSeconds));

        foreach ($this->generate($start, $rule, $earliest) as $occurrence) {
            if ($occurrence->greaterThanOrEqualTo($to)) {
                break;
            }

            if ($occurrence->addSeconds($durationSeconds)->greaterThan($from)
                || ($durationSeconds === 0 && $occurrence->greaterThanOrEqualTo($from))) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /** Whether the series has an occurrence starting exactly at this instant. */
    public function occursAt(CarbonImmutable $start, RecurrenceRule $rule, CarbonInterface $instant): bool
    {
        foreach ($this->between($start, $rule, 0, $instant, CarbonImmutable::instance($instant)->addSecond()) as $occurrence) {
            if ($occurrence->equalTo($instant)) {
                return true;
            }
        }

        return false;
    }

    /** The start of the last occurrence, or null while the series repeats for ever. */
    public function lastStart(CarbonImmutable $start, RecurrenceRule $rule): ?CarbonImmutable
    {
        if ($rule->count === null && $rule->until === null) {
            return null;
        }

        $last = null;

        foreach ($this->generate($start, $rule, null) as $occurrence) {
            $last = $occurrence;
        }

        return $last;
    }

    /** How many occurrences start before the given instant. */
    public function countBefore(CarbonImmutable $start, RecurrenceRule $rule, CarbonInterface $before): int
    {
        $count = 0;

        foreach ($this->generate($start, $rule, null) as $occurrence) {
            if ($occurrence->greaterThanOrEqualTo($before)) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return Generator<int, CarbonImmutable>
     */
    private function generate(CarbonImmutable $start, RecurrenceRule $rule, ?CarbonInterface $skipBefore): Generator
    {
        $zone = $start->getTimezone();
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $start->format('Y-m-d'), 'UTC');
        $skipDate = $skipBefore === null || $rule->count !== null
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', CarbonImmutable::instance($skipBefore)->setTimezone($zone)->format('Y-m-d'), 'UTC');
        [$hour, $minute, $second] = [(int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s')];
        $emitted = 0;

        foreach ($this->dates($first, $rule, $skipDate) as $date) {
            if ($rule->until !== null && $date->format('Y-m-d') > $rule->until) {
                return;
            }

            yield CarbonImmutable::create((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'), $hour, $minute, $second, $zone);

            if ($rule->count !== null && ++$emitted >= $rule->count) {
                return;
            }
        }
    }

    /**
     * Candidate local dates (as UTC midnights) on or after the first date, in order.
     *
     * @return Generator<int, CarbonImmutable>
     */
    private function dates(CarbonImmutable $first, RecurrenceRule $rule, ?CarbonImmutable $skipDate): Generator
    {
        $interval = $rule->interval;
        $steps = 0;

        switch ($rule->frequency) {
            case RecurrenceFrequency::Daily:
                $k = $skipDate === null ? 0 : max(0, intdiv((int) $first->diffInDays($skipDate, false), $interval) - 1);

                while ($steps++ < self::MAX_STEPS) {
                    yield $first->addDays($k * $interval);
                    $k++;
                }

                return;

            case RecurrenceFrequency::Weekly:
                $weekdays = $rule->weekdays === [] ? [(int) $first->format('w')] : $rule->weekdays;
                // Offsets from Monday, in week order.
                $offsets = array_map(static fn (int $day): int => ($day + 6) % 7, $weekdays);
                sort($offsets);
                $monday = $first->subDays(((int) $first->format('w') + 6) % 7);
                $w = $skipDate === null ? 0 : max(0, intdiv(intdiv((int) $monday->diffInDays($skipDate, false), 7), $interval) - 1);

                while ($steps < self::MAX_STEPS) {
                    $weekStart = $monday->addDays($w * $interval * 7);

                    foreach ($offsets as $offset) {
                        $steps++;
                        $date = $weekStart->addDays($offset);

                        if ($date->greaterThanOrEqualTo($first)) {
                            yield $date;
                        }
                    }

                    $w++;
                }

                return;

            case RecurrenceFrequency::Monthly:
                $day = (int) $first->format('j');
                $weekday = (int) $first->format('w');
                $nth = intdiv($day - 1, 7) + 1;
                $base = (int) $first->format('Y') * 12 + (int) $first->format('n') - 1;
                $m = $skipDate === null ? 0 : max(0, intdiv(((int) $skipDate->format('Y') * 12 + (int) $skipDate->format('n') - 1) - $base, $interval) - 1);

                while ($steps++ < self::MAX_STEPS) {
                    $index = $base + $m * $interval;
                    $m++;
                    $monthStart = CarbonImmutable::create(intdiv($index, 12), $index % 12 + 1, 1, 0, 0, 0, 'UTC');
                    $date = match ($rule->monthly) {
                        MonthlyMode::Day => $day <= $monthStart->daysInMonth ? $monthStart->addDays($day - 1) : null,
                        MonthlyMode::Weekday => $this->nthWeekday($monthStart, $weekday, $nth),
                        MonthlyMode::LastWeekday => $this->lastWeekday($monthStart, $weekday),
                    };

                    if ($date !== null && $date->greaterThanOrEqualTo($first)) {
                        yield $date;
                    }
                }

                return;

            case RecurrenceFrequency::Yearly:
                [$year, $month, $day] = [(int) $first->format('Y'), (int) $first->format('n'), (int) $first->format('j')];
                $y = $skipDate === null ? 0 : max(0, intdiv((int) $skipDate->format('Y') - $year, $interval) - 1);

                while ($steps++ < self::MAX_STEPS) {
                    $target = $year + $y * $interval;
                    $y++;
                    $monthStart = CarbonImmutable::create($target, $month, 1, 0, 0, 0, 'UTC');

                    if ($day <= $monthStart->daysInMonth) {
                        yield $monthStart->addDays($day - 1);
                    }
                }

                return;
        }
    }

    private function nthWeekday(CarbonImmutable $monthStart, int $weekday, int $nth): ?CarbonImmutable
    {
        $firstMatch = $monthStart->addDays(($weekday - (int) $monthStart->format('w') + 7) % 7);
        $date = $firstMatch->addDays(($nth - 1) * 7);

        return $date->format('n') === $monthStart->format('n') ? $date : null;
    }

    private function lastWeekday(CarbonImmutable $monthStart, int $weekday): CarbonImmutable
    {
        $last = $monthStart->addDays($monthStart->daysInMonth - 1);

        return $last->subDays(((int) $last->format('w') - $weekday + 7) % 7);
    }
}
