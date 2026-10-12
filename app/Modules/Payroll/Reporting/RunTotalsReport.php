<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\Payroll\Actions\PayrollReports;
use App\Modules\Payroll\Enums\RunKind;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Models\Run;

/**
 * Totals of approved payroll runs for the periods that lie inside the dates. A reversal run is
 * shown with negative amounts so a reversed run nets to zero. Sensitive: `payroll.reports.view`.
 */
final class RunTotalsReport implements ReportProvider
{
    public function __construct(private readonly PayrollReports $reports) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'payroll.run_totals',
            module: 'payroll',
            label: 'Payroll run totals',
            description: 'Gross, deductions, reimbursements, net and employer cost of approved payroll runs for the periods inside the dates. Reversals are negative.',
            permission: 'payroll.reports.view',
            columns: [
                new ReportColumn('period', 'Period'),
                new ReportColumn('run_number', 'Run'),
                new ReportColumn('kind', 'Kind'),
                new ReportColumn('currency', 'Currency'),
                new ReportColumn('employees', 'Employees', ColumnType::Integer),
                new ReportColumn('gross_amount', 'Gross pay', ColumnType::Money, 'currency'),
                new ReportColumn('deduction_amount', 'Deductions', ColumnType::Money, 'currency'),
                new ReportColumn('reimbursement_amount', 'Reimbursements', ColumnType::Money, 'currency', default: false),
                new ReportColumn('net_amount', 'Net pay', ColumnType::Money, 'currency'),
                new ReportColumn('employer_amount', 'Employer cost', ColumnType::Money, 'currency'),
            ],
            filters: [new ReportFilter('currency', 'Currency', FilterType::Currency)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $periodIds = $this->reports->periodIds(null, $context->from, $context->to);

        $runs = Run::query()
            ->whereIn('period_id', $periodIds)
            ->where('status', RunStatus::Approved)
            ->when($context->currency(), fn ($query, string $currency) => $query->where('currency', $currency))
            ->with('period:id,name,starts_on')
            ->orderBy('created_at')->orderBy('id')
            ->limit($context->rowLimit + 1)
            ->get();

        return new ReportResult($runs->map(static function (Run $run): array {
            $sign = $run->kind === RunKind::Reversal ? -1 : 1;

            return [
                'period' => $run->period->name,
                'run_number' => $run->number,
                'kind' => $run->kind->value,
                'currency' => $run->currency,
                'employees' => $run->employee_count,
                'gross_amount' => $sign * $run->gross_amount,
                'deduction_amount' => $sign * $run->deduction_amount,
                'reimbursement_amount' => $sign * $run->reimbursement_amount,
                'net_amount' => $sign * $run->net_amount,
                'employer_amount' => $sign * $run->employer_amount,
            ];
        })->all());
    }
}
