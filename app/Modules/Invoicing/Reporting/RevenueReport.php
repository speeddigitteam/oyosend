<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\Invoicing\Actions\Receivables;

/** What was invoiced (totals, tax, credits) and what was collected in the period, per currency. */
final class RevenueReport implements ReportProvider
{
    public function __construct(private readonly Receivables $receivables) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'invoicing.revenue',
            module: 'invoicing',
            label: 'Revenue',
            description: 'Invoiced totals, tax and credit notes, and payments collected in the period, per currency.',
            permission: 'invoicing.reports.view',
            columns: [
                new ReportColumn('currency', 'Currency'),
                new ReportColumn('invoices', 'Invoices', ColumnType::Integer),
                new ReportColumn('invoiced', 'Invoiced', ColumnType::Money, 'currency'),
                new ReportColumn('tax', 'Tax', ColumnType::Money, 'currency'),
                new ReportColumn('credited', 'Credited', ColumnType::Money, 'currency'),
                new ReportColumn('payments', 'Payments', ColumnType::Integer),
                new ReportColumn('collected', 'Collected', ColumnType::Money, 'currency'),
            ],
            filters: [new ReportFilter('currency', 'Currency', FilterType::Currency)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $report = $this->receivables->report($context->from, $context->to, $context->currency());
        $rows = [];

        foreach ($report['invoiced'] as $row) {
            $rows[$row['currency']] = [
                'currency' => $row['currency'],
                'invoices' => $row['invoices'],
                'invoiced' => $row['total'],
                'tax' => $row['tax'],
                'credited' => $row['credited'],
                'payments' => 0,
                'collected' => 0,
            ];
        }

        foreach ($report['collected'] as $row) {
            $rows[$row['currency']] = ($rows[$row['currency']] ?? [
                'currency' => $row['currency'], 'invoices' => 0, 'invoiced' => 0, 'tax' => 0, 'credited' => 0,
            ]) + ['payments' => 0, 'collected' => 0];
            $rows[$row['currency']]['payments'] = $row['payments'];
            $rows[$row['currency']]['collected'] = $row['amount'];
        }

        ksort($rows);

        return new ReportResult(array_values($rows));
    }
}
