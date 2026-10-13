<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\MetaAds\Actions\PerformanceReport;

/**
 * Spend, reach and leads per day across the tracked ad accounts, one series per currency (days are the ad
 * accounts' own calendar days). A day with no data from Meta is left out, not shown as zero.
 */
final class DailySpendReport implements ReportProvider
{
    public function __construct(private readonly PerformanceReport $performance) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'meta_ads.daily_spend',
            module: 'meta_ads',
            label: 'Meta Ads daily spend',
            description: 'Spend, impressions, clicks and leads per day, in each ad account currency.',
            permission: 'meta_ads.insights.view',
            columns: [
                new ReportColumn('date', 'Date', ColumnType::Date),
                new ReportColumn('currency', 'Currency'),
                new ReportColumn('spend', 'Spend', ColumnType::Money, 'currency'),
                new ReportColumn('impressions', 'Impressions', ColumnType::Integer),
                new ReportColumn('clicks', 'Clicks', ColumnType::Integer),
                new ReportColumn('leads', 'Leads', ColumnType::Integer),
            ],
            filters: [new ReportFilter('currency', 'Currency', FilterType::Currency)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $currencies = $context->currency() !== null ? [$context->currency()] : $this->performance->currencies($context->from, $context->to);
        $rows = [];
        $truncated = false;

        foreach ($currencies as $currency) {
            foreach ($this->performance->series($context->from, $context->to, (string) $currency) as $day) {
                if ($day['spend'] === null && $day['impressions'] === null && $day['clicks'] === null && $day['leads'] === null) {
                    continue;
                }

                if (count($rows) > $context->rowLimit) {
                    $truncated = true;

                    break 2;
                }

                $rows[] = ['date' => $day['date'], 'currency' => (string) $currency, 'spend' => $day['spend'], 'impressions' => $day['impressions'], 'clicks' => $day['clicks'], 'leads' => $day['leads']];
            }
        }

        return new ReportResult($rows, $truncated);
    }
}
