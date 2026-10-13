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

/** Customer satisfaction ratings answered in the period: how many gave each rating, with the overall average. */
final class CsatReport implements ReportProvider
{
    public function __construct(private readonly SupportReports $reports) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'support.csat',
            module: 'support',
            label: 'Customer satisfaction',
            description: 'Satisfaction ratings answered in the period: responses per rating, share of responses and the overall average rating.',
            permission: 'support.reports.view',
            columns: [
                new ReportColumn('rating', 'Rating', ColumnType::Integer),
                new ReportColumn('responses', 'Responses', ColumnType::Integer),
                new ReportColumn('share_bps', 'Share of responses', ColumnType::BasisPoints),
                new ReportColumn('average_hundredths', 'Average rating', ColumnType::Hundredths),
            ],
            filters: [new ReportFilter('queue_id', 'Queue', FilterType::Id)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $csat = $this->reports->overview($context->from, $context->to, $context->string('queue_id'), ['csat'])['csat'];
        $total = $csat['responses'];
        $rows = [];

        foreach ($csat['distribution'] as $rating => $count) {
            $rows[] = [
                'rating' => $rating,
                'responses' => $count,
                'share_bps' => $total === 0 ? null : intdiv($count * 20000 + $total, $total * 2),
                // The same overall figure on every row: null when nobody answered (not reported, not zero).
                'average_hundredths' => $csat['averageHundredths'],
            ];
        }

        return new ReportResult($rows);
    }
}
