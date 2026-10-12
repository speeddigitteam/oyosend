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

/** Open balances by how overdue they are, per currency, as of today. A snapshot: the date range is ignored. */
final class ReceivablesAgeingReport implements ReportProvider
{
    public function __construct(private readonly Receivables $receivables) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'invoicing.receivables_ageing',
            module: 'invoicing',
            label: 'Receivables ageing',
            description: 'Outstanding invoice balances by how overdue they are, per currency, as of today.',
            permission: 'invoicing.reports.view',
            columns: [
                new ReportColumn('currency', 'Currency'),
                new ReportColumn('invoices', 'Open invoices', ColumnType::Integer),
                new ReportColumn('outstanding', 'Outstanding', ColumnType::Money, 'currency'),
                new ReportColumn('current', 'Not yet due', ColumnType::Money, 'currency'),
                new ReportColumn('days_1_30', '1-30 days overdue', ColumnType::Money, 'currency'),
                new ReportColumn('days_31_60', '31-60 days overdue', ColumnType::Money, 'currency'),
                new ReportColumn('days_61_90', '61-90 days overdue', ColumnType::Money, 'currency'),
                new ReportColumn('days_over_90', 'Over 90 days overdue', ColumnType::Money, 'currency'),
            ],
            filters: [new ReportFilter('currency', 'Currency', FilterType::Currency)],
            usesDateRange: false,
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $report = $this->receivables->report(null, null, $context->currency());

        return new ReportResult(array_map(static fn (array $row): array => [
            'currency' => $row['currency'],
            'invoices' => $row['invoices'],
            'outstanding' => $row['outstanding'],
            ...$row['buckets'],
        ], $report['ageing']));
    }
}
