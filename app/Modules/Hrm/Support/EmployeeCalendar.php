<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\Shift;

/**
 * What one employee's working calendar looks like over a date range: which shift
 * applies on each local date, which dates are holidays and which are weekly days off.
 * Built by {@see WorkCalendars}; holds no queries of its own.
 */
final class EmployeeCalendar
{
    /**
     * @param  list<array{from: string, to: string|null, shift: Shift}>  $assignments  newest start first
     * @param  array<string, string>  $holidays  date => name
     * @param  list<int>  $settingsOffDays  ISO weekdays
     */
    public function __construct(
        private readonly array $assignments,
        private readonly ?Shift $default,
        private readonly array $holidays,
        private readonly array $settingsOffDays,
        public readonly int $fallbackMinutes,
    ) {}

    public function shiftOn(string $date): ?Shift
    {
        foreach ($this->assignments as $assignment) {
            if ($assignment['from'] <= $date && ($assignment['to'] === null || $assignment['to'] >= $date)) {
                return $assignment['shift'];
            }
        }

        return $this->default;
    }

    public function isHoliday(string $date): bool
    {
        return isset($this->holidays[$date]);
    }

    public function holidayName(string $date): ?string
    {
        return $this->holidays[$date] ?? null;
    }

    public function isWeeklyOff(string $date): bool
    {
        $weekday = (int) date('N', (int) strtotime($date.' 12:00:00 UTC'));
        $shift = $this->shiftOn($date);
        $days = $shift !== null && $shift->weekly_off_days !== null ? $shift->weekly_off_days : $this->settingsOffDays;

        return in_array($weekday, $days, true);
    }

    public function isWorkingDay(string $date): bool
    {
        return ! $this->isHoliday($date) && ! $this->isWeeklyOff($date);
    }
}
