<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\Hrm\Actions\HrmReports;
use App\Modules\Hrm\Models\AttendanceDay;

/** Attendance totals per employee over closed days. Only the employees you may see are included. */
final class AttendanceReport implements ReportProvider
{
    public const string PERMISSION = 'hrm.attendance.view';

    public function __construct(
        private readonly HrmReports $reports,
        private readonly RecordAccess $records,
    ) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hrm.attendance',
            module: 'hrm',
            label: 'Attendance summary',
            description: 'Days present, absent and on leave, late days and worked time per employee over closed days. Only the employees you may see are included.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('employee_number', 'Employee number'),
                new ReportColumn('name', 'Employee'),
                new ReportColumn('present', 'Present', ColumnType::Integer),
                new ReportColumn('half_day', 'Half days', ColumnType::Integer),
                new ReportColumn('absent', 'Absent', ColumnType::Integer),
                new ReportColumn('on_leave', 'On leave', ColumnType::Integer),
                new ReportColumn('late_days', 'Late days', ColumnType::Integer),
                new ReportColumn('scheduled_minutes', 'Scheduled time', ColumnType::Minutes),
                new ReportColumn('worked_minutes', 'Worked time', ColumnType::Minutes),
                new ReportColumn('late_minutes', 'Late time', ColumnType::Minutes, default: false),
                new ReportColumn('overtime_minutes', 'Overtime', ColumnType::Minutes, default: false),
            ],
            filters: [new ReportFilter('department_id', 'Department', FilterType::Id)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $days = $this->records->constrain(AttendanceDay::query(), $context->user, self::PERMISSION)
            ->when($context->string('department_id'), fn ($query, string $id) => $query->where('hrm_attendance_days.department_id', $id));

        $page = $this->reports->attendanceByEmployee($days, $context->from, $context->to, $context->rowLimit + 1);

        return new ReportResult(array_map(static fn (array $row): array => [
            'employee_number' => $row['employeeNumber'],
            'name' => $row['name'],
            'present' => $row['present'],
            'half_day' => $row['halfDay'],
            'absent' => $row['absent'],
            'on_leave' => $row['onLeave'],
            'late_days' => $row['lateDays'],
            'scheduled_minutes' => $row['scheduledMinutes'],
            'worked_minutes' => $row['workedMinutes'],
            'late_minutes' => $row['lateMinutes'],
            'overtime_minutes' => $row['overtimeMinutes'],
        ], $page->items()));
    }
}
