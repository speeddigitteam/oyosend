<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Enums\HolidayType;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShiftAssignment;

/** Builds {@see EmployeeCalendar} objects with two queries per employee and range. */
final class WorkCalendars
{
    public function __construct(private readonly HrmSettings $settings) {}

    public function forEmployee(string $employeeId, string $from, string $to): EmployeeCalendar
    {
        $settings = $this->settings->current();

        $assignments = ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->where('starts_on', '<=', $to)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $from))
            ->orderByDesc('starts_on')
            ->get()
            ->map(static fn (ShiftAssignment $assignment): array => [
                'from' => $assignment->starts_on->toDateString(),
                'to' => $assignment->ends_on?->toDateString(),
                'shift' => $assignment->shift,
            ])
            ->all();

        return new EmployeeCalendar(
            $assignments,
            Shift::query()->where('is_default', true)->where('is_active', true)->first(),
            $this->holidays($from, $to),
            $settings->weekly_off_days,
            $settings->work_day_minutes,
        );
    }

    /** @return array<string, string> date => name (optional holidays are working days and are left out) */
    public function holidays(string $from, string $to): array
    {
        return Holiday::query()
            ->where('type', HolidayType::Public)
            ->whereBetween('holiday_on', [$from, $to])
            ->get()
            ->mapWithKeys(static fn (Holiday $holiday): array => [$holiday->holiday_on->toDateString() => $holiday->name])
            ->all();
    }
}
