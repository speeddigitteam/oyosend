<?php

declare(strict_types=1);

namespace App\Modules\Support\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\Support\Actions\SupportReports;

/** Tickets created and resolved per day (workspace-wide, merged tickets left out). */
final class TicketVolumeReport implements ReportProvider
{
    public function __construct(private readonly SupportReports $reports) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'support.volume',
            module: 'support',
            label: 'Ticket volume',
            description: 'Tickets created and resolved per day in the period.',
            permission: 'support.reports.view',
            columns: [
                new ReportColumn('date', 'Date', ColumnType::Date),
                new ReportColumn('created', 'Created', ColumnType::Integer),
                new ReportColumn('resolved', 'Resolved', ColumnType::Integer),
            ],
            filters: [new ReportFilter('queue_id', 'Queue', FilterType::Id)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $overview = $this->reports->overview($context->from, $context->to, $context->string('queue_id'), ['volume']);

        return new ReportResult($overview['volume']['byDay']);
    }
}
