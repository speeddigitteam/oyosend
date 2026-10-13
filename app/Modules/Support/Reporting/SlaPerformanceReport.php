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

/** First-response and resolution SLA outcomes and times for tickets created in the period. */
final class SlaPerformanceReport implements ReportProvider
{
    public function __construct(private readonly SupportReports $reports) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'support.sla',
            module: 'support',
            label: 'SLA performance',
            description: 'Targets met, missed and pending, and average and median times, for first response and resolution of tickets created in the period.',
            permission: 'support.reports.view',
            columns: [
                new ReportColumn('target', 'Target'),
                new ReportColumn('met', 'Met', ColumnType::Integer),
                new ReportColumn('missed', 'Missed', ColumnType::Integer),
                new ReportColumn('pending', 'Pending', ColumnType::Integer),
                new ReportColumn('rate_bps', 'Met rate', ColumnType::BasisPoints),
                new ReportColumn('average_minutes', 'Average time', ColumnType::Minutes),
                new ReportColumn('median_minutes', 'Median time', ColumnType::Minutes),
            ],
            filters: [new ReportFilter('queue_id', 'Queue', FilterType::Id)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $overview = $this->reports->overview($context->from, $context->to, $context->string('queue_id'), ['sla', 'times']);
        $sla = $overview['sla'];
        $times = $overview['times'];

        return new ReportResult([
            [
                'target' => 'first_response',
                'met' => $sla['firstResponse']['met'],
                'missed' => $sla['firstResponse']['missed'],
                'pending' => $sla['firstResponse']['pending'],
                'rate_bps' => $sla['firstResponse']['rateBps'],
                'average_minutes' => $times['firstResponseAverage'],
                'median_minutes' => $times['firstResponseMedian'],
            ],
            [
                'target' => 'resolution',
                'met' => $sla['resolution']['met'],
                'missed' => $sla['resolution']['missed'],
                'pending' => $sla['resolution']['pending'],
                'rate_bps' => $sla['resolution']['rateBps'],
                'average_minutes' => $times['resolutionAverage'],
                'median_minutes' => $times['resolutionMedian'],
            ],
        ]);
    }
}
