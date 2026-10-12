<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * The attendance arithmetic, with no database access and no clock.
 *
 * Every instant is first floored to the whole minute, then all differences are
 * integer minute counts, so there is no rounding and no float. The shift's
 * wall-clock times are interpreted in the workspace timezone on the work date.
 *
 *  - worked   sum of the merged clocked intervals; the shift's break is deducted
 *             only when the day has exactly one continuous entry longer than the break;
 *  - late     minutes the first clock-in is after the shift start, 0 within the grace;
 *  - early    minutes the last clock-out is before the shift end, 0 within the grace;
 *  - overtime minutes after the shift end once the threshold is met; on a weekly off or
 *             holiday everything worked is overtime.
 */
final class AttendanceMath
{
    /**
     * @param  list<array{in: CarbonImmutable, out: CarbonImmutable|null}>  $entries
     * @return array{
     *     status: DayStatus, scheduled_minutes: int, worked_minutes: int, late_minutes: int,
     *     early_leave_minutes: int, overtime_minutes: int, first_in_at: CarbonImmutable|null,
     *     last_out_at: CarbonImmutable|null, entries_count: int, has_open_entry: bool
     * }
     */
    public static function evaluate(
        string $workDate,
        string $timezone,
        ?Shift $shift,
        array $entries,
        bool $isHoliday,
        bool $isWeeklyOff,
        int $leaveUnits,
        int $fallbackScheduledMinutes,
    ): array {
        $offDay = $isHoliday || $isWeeklyOff;
        $scheduled = $offDay ? 0 : ($shift?->scheduledMinutes() ?? $fallbackScheduledMinutes);

        $closed = [];
        $hasOpen = false;

        foreach ($entries as $entry) {
            if ($entry['out'] === null) {
                $hasOpen = true;

                continue;
            }

            $from = self::minute($entry['in']);
            $to = self::minute($entry['out']);

            if ($to > $from) {
                $closed[] = [$from, $to];
            }
        }

        $merged = self::merge($closed);
        $worked = 0;

        foreach ($merged as [$from, $to]) {
            $worked += $to - $from;
        }

        if ($shift !== null && $shift->break_minutes > 0 && count($entries) === 1 && count($merged) === 1 && $worked > $shift->break_minutes) {
            $worked -= $shift->break_minutes;
        }

        $firstIn = $merged === [] ? null : CarbonImmutable::createFromTimestampUTC($merged[0][0] * 60);
        $lastOut = $merged === [] ? null : CarbonImmutable::createFromTimestampUTC($merged[count($merged) - 1][1] * 60);
        $late = 0;
        $early = 0;
        $overtime = 0;

        if ($offDay) {
            $overtime = $worked;
        } elseif ($shift !== null && $firstIn !== null && $lastOut !== null) {
            $start = self::minute(self::shiftStart($workDate, $timezone, $shift));
            $end = self::minute(self::shiftEnd($workDate, $timezone, $shift));
            $firstMinute = intdiv($firstIn->getTimestamp(), 60);
            $lastMinute = intdiv($lastOut->getTimestamp(), 60);

            $lateBy = $firstMinute - $start;
            $late = $lateBy > $shift->late_grace_minutes ? $lateBy : 0;

            $earlyBy = $end - $lastMinute;
            $early = $earlyBy > $shift->early_grace_minutes ? $earlyBy : 0;

            $over = $lastMinute - $end;
            $overtime = $over > 0 && $over >= $shift->overtime_threshold_minutes ? $over : 0;
        }

        $status = match (true) {
            $isHoliday => DayStatus::Holiday,
            $isWeeklyOff => DayStatus::WeeklyOff,
            $leaveUnits >= LeaveUnits::PER_DAY => DayStatus::OnLeave,
            $worked > 0 => $worked >= self::halfDayThreshold($shift, $scheduled) ? DayStatus::Present : DayStatus::HalfDay,
            $hasOpen => DayStatus::Incomplete,
            $leaveUnits > 0 => DayStatus::HalfDay,
            default => DayStatus::Absent,
        };

        return [
            'status' => $status,
            'scheduled_minutes' => $scheduled,
            'worked_minutes' => $worked,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'overtime_minutes' => $overtime,
            'first_in_at' => $firstIn,
            'last_out_at' => $lastOut,
            'entries_count' => count($entries),
            'has_open_entry' => $hasOpen,
        ];
    }

    public static function shiftStart(string $workDate, string $timezone, Shift $shift): CarbonImmutable
    {
        return CarbonImmutable::parse($workDate, $timezone)->startOfDay()
            ->setTime(intdiv($shift->startMinute(), 60), $shift->startMinute() % 60)
            ->utc();
    }

    public static function shiftEnd(string $workDate, string $timezone, Shift $shift): CarbonImmutable
    {
        $endMinute = Shift::minuteOf($shift->ends_at);
        $day = CarbonImmutable::parse($workDate, $timezone)->startOfDay();

        if ($shift->crossesMidnight()) {
            $day = $day->addDay();
        }

        return $day->setTime(intdiv($endMinute, 60), $endMinute % 60)->utc();
    }

    private static function halfDayThreshold(?Shift $shift, int $scheduled): int
    {
        if ($shift !== null && $shift->half_day_minutes !== null) {
            return $shift->half_day_minutes;
        }

        return intdiv($scheduled, 2);
    }

    /** The whole minute (since the epoch) an instant falls in. */
    private static function minute(CarbonImmutable $instant): int
    {
        return intdiv($instant->getTimestamp(), 60);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private static function merge(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($intervals as [$from, $to]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);

                continue;
            }

            $merged[] = [$from, $to];
        }

        return $merged;
    }
}
