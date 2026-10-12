<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Closes finished attendance days. A day is closed once, on a local date before today,
 * for every employee who was expected to work; the closure computes holidays, weekly
 * offs, leave and absences and announces `hrm.attendance.day_closed`. Closing is
 * idempotent: a closed day is skipped, so reruns and overlapping workers do nothing twice.
 */
final class AttendanceCloser
{
    /** How far back a run looks for days that were never closed. */
    public const int LOOKBACK_DAYS = 45;

    public function __construct(
        private readonly HrmSettings $settings,
        private readonly AttendanceManager $attendance,
    ) {}

    /** @return int Days closed in this run. */
    public function run(?CarbonImmutable $today = null): int
    {
        $today ??= $this->settings->today();
        $closed = 0;

        Employee::query()
            ->whereIn('status', [EmployeeStatus::Probation, EmployeeStatus::Active])
            ->chunkById(50, function ($employees) use ($today, &$closed): void {
                foreach ($employees as $employee) {
                    $closed += $this->closeEmployee($employee, $today->subDay()->toDateString(), $today);
                }
            });

        return $closed;
    }

    /**
     * Close every unclosed day of an employee up to (and including) a local date.
     *
     * @return int Days closed.
     */
    public function closeEmployee(Employee $employee, string $upTo, ?CarbonImmutable $today = null): int
    {
        $today ??= $this->settings->today();
        $from = max($employee->joined_on->toDateString(), $today->subDays(self::LOOKBACK_DAYS)->toDateString());
        $upTo = min($upTo, $today->subDay()->toDateString());

        if ($from > $upTo) {
            return 0;
        }

        $done = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->where('is_closed', true)
            ->whereBetween('work_date', [$from, $upTo])
            ->get(['work_date'])
            ->map(static fn (AttendanceDay $day): string => $day->work_date->toDateString())
            ->flip();

        $closed = 0;
        $date = CarbonImmutable::parse($from, 'UTC');
        $end = CarbonImmutable::parse($upTo, 'UTC');

        while ($date <= $end) {
            $key = $date->toDateString();

            if (! $done->has($key) && $this->attendance->closeDay($employee, $key) !== null) {
                $closed++;
            }

            $date = $date->addDay();
        }

        return $closed;
    }
}
