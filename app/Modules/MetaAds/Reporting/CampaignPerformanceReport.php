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
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Models\AdAccount;

/**
 * Spend and results per campaign in the period. One row per campaign and currency: amounts of different
 * currencies are never added together. Empty means Meta did not report the figure.
 */
final class CampaignPerformanceReport implements ReportProvider
{
    public function __construct(private readonly PerformanceReport $performance) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'meta_ads.campaign_performance',
            module: 'meta_ads',
            label: 'Meta Ads campaign performance',
            description: 'Spend, reach and results per campaign for the period, in each ad account currency. Empty means Meta did not report the figure.',
            permission: 'meta_ads.insights.view',
            columns: [
                new ReportColumn('account', 'Ad account'),
                new ReportColumn('campaign', 'Campaign'),
                new ReportColumn('currency', 'Currency'),
                new ReportColumn('spend', 'Spend', ColumnType::Money, 'currency'),
                new ReportColumn('impressions', 'Impressions', ColumnType::Integer),
                new ReportColumn('clicks', 'Clicks', ColumnType::Integer),
                new ReportColumn('ctr', 'CTR', ColumnType::BasisPoints),
                new ReportColumn('cpc', 'Cost per click', ColumnType::Money, 'currency'),
                new ReportColumn('leads', 'Leads', ColumnType::Integer),
                new ReportColumn('cost_per_lead', 'Cost per lead', ColumnType::Money, 'currency'),
            ],
            filters: [
                new ReportFilter('currency', 'Currency', FilterType::Currency),
                new ReportFilter('account', 'Ad account', FilterType::Id),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $accountId = $context->string('account');

        if ($accountId !== null && AdAccount::query()->whereKey($accountId)->where('status', AdAccountStatus::Tracked->value)->doesntExist()) {
            return new ReportResult([]);
        }

        $currencies = $context->currency() !== null ? [$context->currency()] : $this->performance->currencies($context->from, $context->to);
        $rows = [];
        $truncated = false;

        foreach ($currencies as $currency) {
            foreach ($this->performance->campaigns($context->from, $context->to, (string) $currency, $accountId, $context->rowLimit + 1) as $row) {
                if (count($rows) > $context->rowLimit) {
                    $truncated = true;

                    break 2;
                }

                $rows[] = [
                    'account' => $row['accountName'],
                    'campaign' => $row['name'] ?? $row['campaignId'],
                    'currency' => $row['currency'],
                    'spend' => $row['spend'],
                    'impressions' => $row['impressions'],
                    'clicks' => $row['clicks'],
                    'ctr' => $row['ctr'],
                    'cpc' => $row['cpc'],
                    'leads' => $row['leads'],
                    'cost_per_lead' => $row['costPerLead'],
                ];
            }
        }

        return new ReportResult($rows, $truncated);
    }
}
