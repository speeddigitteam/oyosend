<?php

declare(strict_types=1);

namespace App\Core\Workforce\Contracts;

/**
 * Attendance and leave facts per employee and period, for Payroll and similar
 * consumers. Same rules as {@see EmployeeDirectory}: bound by `hrm`, tenant
 * scoped, system-level, integers only.
 */
interface EmployeeTimeSummary
{
    /**
     * Attendance facts for the local dates `$from`..`$to` (`Y-m-d`, inclusive, at most 366 days).
     * Only closed days are counted, so a payroll run should not read a period that has open days
     * (`open_days > 0`).
     *
     * @return array{
     *     from: string, to: string, days_present: int, days_half: int, days_absent: int, days_on_leave: int,
     *     days_holiday: int, days_weekly_off: int, days_incomplete: int, open_days: int,
     *     scheduled_minutes: int, worked_minutes: int, late_minutes: int, early_leave_minutes: int,
     *     overtime_minutes: int
     * }
     */
    public function attendance(string $employeeId, string $from, string $to): array;

    /**
     * Approved leave inside the period, per leave type, in integer units (one day = 2 units).
     * Requests that straddle the period are clipped to it.
     *
     * @return list<array{leave_type_id: string, code: string, name: string, is_paid: bool, units: int}>
     */
    public function leaveUsage(string $employeeId, string $from, string $to): array;
}
