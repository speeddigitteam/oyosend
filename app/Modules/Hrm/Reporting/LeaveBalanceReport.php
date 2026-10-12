<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\Hrm\Actions\HrmReports;

/** Leave granted, taken, pending and remaining per leave type for one leave year (workspace-wide). */
final class LeaveBalanceReport implements ReportProvider
{
    public function __construct(private readonly HrmReports $reports) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hrm.leave',
            module: 'hrm',
            label: 'Leave balances',
            description: 'Leave granted, taken, pending and remaining per leave type for one leave year, in leave units.',
            permission: 'hrm.reports.view',
            columns: [
                new ReportColumn('leave_type', 'Leave type'),
                new ReportColumn('code', 'Code'),
                new ReportColumn('granted', 'Granted', ColumnType::Integer),
                new ReportColumn('used', 'Taken', ColumnType::Integer),
                new ReportColumn('pending', 'Pending', ColumnType::Integer),
                new ReportColumn('balance', 'Balance', ColumnType::Integer),
            ],
            filters: [new ReportFilter('year', 'Leave year', FilterType::Integer, min: 2000, max: 2100)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $year = $context->integer('year') ?? (int) substr($context->to, 0, 4);
        $leave = $this->reports->leave($year, $context->from, $context->to);

        return new ReportResult(array_map(static fn (array $row): array => [
            'leave_type' => $row['name'],
            'code' => $row['code'],
            'granted' => $row['granted'],
            'used' => $row['used'],
            'pending' => $row['pending'],
            'balance' => $row['balance'],
        ], $leave['by_type']));
    }
}
