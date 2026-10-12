<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Business-hours arithmetic for SLA clocks. Pure: no database, no tenant context.
 *
 * Working windows are local wall-clock ranges per ISO weekday (1 = Monday ... 7 = Sunday) in the
 * calendar's timezone; holidays are local `Y-m-d` dates with no working time. Moments are real instants,
 * so daylight-saving changes are honoured by walking each local day's windows. Durations are integer
 * minutes; sub-minute remainders are floored when counting.
 */
final class BusinessCalendar
{
    private const int MAX_DAYS = 800;

    /**
     * @param  array<int, list<array{0: string, 1: string}>>  $windows
     * @param  list<string>  $holidays
     */
    public function __construct(
        public readonly string $timezone,
        private readonly array $windows = [],
        private readonly array $holidays = [],
        private readonly bool $alwaysOpen = false,
    ) {}

    /** A calendar with no restrictions: every minute counts. */
    public static function always(string $timezone = 'UTC'): self
    {
        return new self($timezone, [], [], true);
    }

    /**
     * @param  array<int|string, mixed>  $hours  ISO weekday => list of [start, end] `HH:MM` pairs.
     * @param  list<string>  $holidays
     */
    public static function make(string $timezone, array $hours, array $holidays = []): self
    {
        return new self($timezone, self::normalizeHours($hours), array_values(array_unique($holidays)));
    }

    /**
     * Validates and sorts weekly windows. `24:00` is accepted as an end. Throws on a bad weekday, a bad time,
     * an empty window or overlapping windows, and when no day has any window.
     *
     * @param  array<int|string, mixed>  $hours
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public static function normalizeHours(array $hours): array
    {
        $normalized = [];

        foreach ($hours as $day => $windows) {
            $weekday = (int) $day;

            if ($weekday < 1 || $weekday > 7 || ! is_array($windows)) {
                throw new InvalidArgumentException('Working days are numbered 1 (Monday) to 7 (Sunday).');
            }

            $parsed = [];

            foreach ($windows as $window) {
                if (! is_array($window) || count($window) !== 2) {
                    throw new InvalidArgumentException('A working window is a start and an end time.');
                }

                $pair = array_values($window);
                $start = self::minuteOfDay((string) $pair[0]);
                $end = self::minuteOfDay((string) $pair[1]);

                if ($end <= $start) {
                    throw new InvalidArgumentException('A working window must end after it starts.');
                }

                $parsed[] = [$start, $end];
            }

            usort($parsed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            for ($i = 1; $i < count($parsed); $i++) {
                if ($parsed[$i][0] < $parsed[$i - 1][1]) {
                    throw new InvalidArgumentException('Working windows of one day must not overlap.');
                }
            }

            if ($parsed !== []) {
                $normalized[$weekday] = array_map(
                    static fn (array $w): array => [self::format($w[0]), self::format($w[1])],
                    $parsed,
                );
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('A business calendar needs at least one working window.');
        }

        ksort($normalized);

        return $normalized;
    }

    /** The instant `$minutes` of working time after `$from`. */
    public function addMinutes(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $start = CarbonImmutable::instance($from)->utc();

        if ($minutes <= 0) {
            return $start;
        }

        if ($this->alwaysOpen) {
            return $start->addMinutes($minutes);
        }

        $remaining = $minutes * 60;
        $cursor = $start->getTimestamp();

        foreach ($this->windowsFrom($cursor) as [$windowStart, $windowEnd]) {
            if ($windowEnd <= $cursor) {
                continue;
            }

            $begin = max($windowStart, $cursor);
            $available = $windowEnd - $begin;

            if ($remaining <= $available) {
                return CarbonImmutable::createFromTimestampUTC($begin + $remaining);
            }

            $remaining -= $available;
        }

        throw new RuntimeException('The calendar has no working time in the next '.self::MAX_DAYS.' days.');
    }

    /** Whole working minutes between two instants; 0 when `$to` is not after `$from`. */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = $from->getTimestamp();
        $end = $to->getTimestamp();

        if ($end <= $start) {
            return 0;
        }

        if ($this->alwaysOpen) {
            return intdiv($end - $start, 60);
        }

        $seconds = 0;

        foreach ($this->windowsFrom($start) as [$windowStart, $windowEnd]) {
            if ($windowStart >= $end) {
                break;
            }

            $seconds += max(0, min($windowEnd, $end) - max($windowStart, $start));
        }

        return intdiv($seconds, 60);
    }

    public function isWorking(CarbonInterface $at): bool
    {
        if ($this->alwaysOpen) {
            return true;
        }

        $timestamp = $at->getTimestamp();

        foreach ($this->windowsFrom($timestamp, 2) as [$start, $end]) {
            if ($timestamp >= $start && $timestamp < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Working windows as `[startTimestamp, endTimestamp]`, in order, from the local day of `$timestamp`.
     *
     * @return Generator<int, array{0: int, 1: int}>
     */
    private function windowsFrom(int $timestamp, int $days = self::MAX_DAYS): Generator
    {
        $day = CarbonImmutable::createFromTimestamp($timestamp, $this->timezone)->startOfDay();

        for ($i = 0; $i < $days; $i++) {
            if (! in_array($day->toDateString(), $this->holidays, true)) {
                foreach ($this->windows[$day->dayOfWeekIso] ?? [] as [$start, $end]) {
                    yield [$this->at($day, $start), $this->at($day, $end)];
                }
            }

            $day = $day->addDay()->startOfDay();
        }
    }

    private function at(CarbonImmutable $day, string $time): int
    {
        if ($time === '24:00') {
            return $day->addDay()->startOfDay()->getTimestamp();
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->setTime($hour, $minute)->getTimestamp();
    }

    private static function minuteOfDay(string $time): int
    {
        if ($time === '24:00') {
            return 1440;
        }

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $match)) {
            throw new InvalidArgumentException('Times are written HH:MM.');
        }

        return ((int) $match[1]) * 60 + (int) $match[2];
    }

    private static function format(int $minutes): string
    {
        return $minutes === 1440 ? '24:00' : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
