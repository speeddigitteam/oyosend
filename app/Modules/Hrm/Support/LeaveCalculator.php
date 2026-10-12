<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\LeaveSession;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How many leave units a date range costs. Pure: it only asks the calendar.
 *
 *  - a working day is {@see LeaveUnits::PER_DAY} units; holidays and weekly days off cost nothing;
 *  - the first day may be taken from the second half (1 unit), the last day up to the first half
 *    (1 unit); a single day may be a first or second half (1 unit);
 *  - with the sandwich rule, days off lying between the first and the last working day of the
 *    range count as leave too (full units).
 */
final class LeaveCalculator
{
    /**
     * @return array{units: int, days: array<string, int>} per-date units (only dates that cost something)
     */
    public static function calculate(
        EmployeeCalendar $calendar,
        string $from,
        string $to,
        LeaveSession $startSession,
        LeaveSession $endSession,
        bool $sandwich,
    ): array {
        if ($to < $from) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }

        $single = $from === $to;

        if ($single) {
            $endSession = $startSession;
        } elseif ($startSession === LeaveSession::FirstHalf || $endSession === LeaveSession::SecondHalf) {
            throw new InvalidArgumentException('A multi-day request starts with a full day or the second half and ends with a full day or the first half.');
        }

        $days = [];
        $workingDates = [];
        $date = CarbonImmutable::parse($from, 'UTC');
        $last = CarbonImmutable::parse($to, 'UTC');
        $guard = 0;

        while ($date <= $last) {
            if (++$guard > 400) {
                throw new InvalidArgumentException('A leave request cannot be longer than 400 days.');
            }

            $key = $date->toDateString();

            if ($calendar->isWorkingDay($key)) {
                $units = LeaveUnits::PER_DAY;

                if (($key === $from && $startSession !== LeaveSession::Full)
                    || ($key === $to && $endSession !== LeaveSession::Full)) {
                    $units = 1;
                }

                $days[$key] = $units;
                $workingDates[] = $key;
            }

            $date = $date->addDay();
        }

        if ($sandwich && count($workingDates) >= 2) {
            $first = $workingDates[0];
            $final = $workingDates[count($workingDates) - 1];
            $date = CarbonImmutable::parse($first, 'UTC')->addDay();

            while ($date->toDateString() < $final) {
                $key = $date->toDateString();
                $days[$key] ??= LeaveUnits::PER_DAY;
                $date = $date->addDay();
            }
        }

        ksort($days);

        return ['units' => array_sum($days), 'days' => $days];
    }
}
