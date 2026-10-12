<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Hrm\Actions\HrmReports;

/** Current headcount by department, branch, designation, employment type and status, plus joiners and leavers in the period. */
final class HeadcountReport implements ReportProvider
{
    public function __construct(private readonly HrmReports $reports) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hrm.headcount',
            module: 'hrm',
            label: 'Headcount',
            description: 'Employees who have not left, by department, branch, designation, employment type and status, plus joiners and leavers in the period.',
            permission: 'hrm.reports.view',
            columns: [
                new ReportColumn('dimension', 'Grouped by'),
                new ReportColumn('name', 'Group'),
                new ReportColumn('count', 'Employees', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $headcount = $this->reports->headcount($context->from, $context->to);
        $rows = [['dimension' => 'total', 'name' => null, 'count' => $headcount['total']]];

        foreach (['department' => 'by_department', 'branch' => 'by_branch', 'designation' => 'by_designation'] as $dimension => $key) {
            foreach ($headcount[$key] as $group) {
                $rows[] = ['dimension' => $dimension, 'name' => $group['name'] ?? __('Not assigned'), 'count' => $group['count']];
            }
        }

        foreach ($headcount['by_employment_type'] as $type => $count) {
            $rows[] = ['dimension' => 'employment_type', 'name' => (string) $type, 'count' => $count];
        }

        foreach ($headcount['by_status'] as $status => $count) {
            $rows[] = ['dimension' => 'status', 'name' => (string) $status, 'count' => $count];
        }

        $rows[] = ['dimension' => 'period', 'name' => 'joined', 'count' => $headcount['joined']];
        $rows[] = ['dimension' => 'period', 'name' => 'left', 'count' => $headcount['left']];

        return new ReportResult(array_slice($rows, 0, $context->rowLimit + 1));
    }
}
