<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Organization\Models\Department;
use App\Modules\Hrm\Actions\HrmReports;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Models\AttendanceDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Workspace-wide HR reports (`hrm.reports.view`): headcount, attendance and leave. */
final class ReportController
{
    /** The longest attendance period a report covers. */
    private const int MAX_DAYS = 92;

    public function __construct(
        private readonly HrmReports $reports,
        private readonly HrmSettings $settings,
        private readonly HrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $today = $this->support->today();
        $from = $this->support->date($request, 'from', $today->startOfMonth()->toDateString());
        $to = $this->support->date($request, 'to', $today->toDateString());

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        if ($from < CarbonImmutable::parse($to, 'UTC')->subDays(self::MAX_DAYS - 1)->toDateString()) {
            $from = CarbonImmutable::parse($to, 'UTC')->subDays(self::MAX_DAYS - 1)->toDateString();
        }

        $department = $request->validate(['department_id' => ['nullable', 'string', 'size:26']])['department_id'] ?? null;
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? $this->settings->leaveYear($today->toDateString()));

        $days = AttendanceDay::query();

        if ($department !== null && $department !== '') {
            $days->where('department_id', $department);
        }

        return Inertia::render('Modules/Hrm/Pages/Reports/Index', [
            'from' => $from,
            'to' => $to,
            'filters' => ['department_id' => $department ?? '', 'year' => $year],
            'headcount' => $this->reports->headcount($from, $to),
            'attendance' => $this->reports->attendanceByEmployee($days, $from, $to)->withQueryString(),
            'leave' => $this->reports->leave($year, $from, $to),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(static fn (Department $item): array => ['id' => $item->id, 'name' => $item->name])->all(),
            'maxDays' => self::MAX_DAYS,
        ]);
    }
}
