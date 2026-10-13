<?php

declare(strict_types=1);

namespace App\Modules\Social\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Actions\PagePerformance;

/**
 * Content campaigns: what the posts published in the period achieved, per campaign, from the
 * latest figures each platform returned for them. Posts without figures are counted as posts
 * but add nothing to the totals; a total no post reported is empty (not zero). Posts on
 * accounts the viewer may not see analytics for are left out.
 */
final class CampaignPerformanceReport implements ReportProvider
{
    public function __construct(
        private readonly PagePerformance $performance,
        private readonly AccountAllowList $allowList,
    ) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'social.campaign_performance',
            module: 'social',
            label: 'Social campaign performance',
            description: 'Posts published per content campaign with the impressions, reach, likes, comments, shares and clicks the platforms reported. Empty means no post reported the figure.',
            permission: 'social.analytics.view',
            columns: [
                new ReportColumn('campaign', 'Campaign'),
                new ReportColumn('posts', 'Posts', ColumnType::Integer),
                new ReportColumn('reported', 'Posts with figures', ColumnType::Integer),
                new ReportColumn('impressions', 'Impressions', ColumnType::Integer),
                new ReportColumn('reach', 'Reach', ColumnType::Integer),
                new ReportColumn('likes', 'Likes', ColumnType::Integer),
                new ReportColumn('comments', 'Comments', ColumnType::Integer),
                new ReportColumn('shares', 'Shares', ColumnType::Integer),
                new ReportColumn('clicks', 'Clicks', ColumnType::Integer),
                new ReportColumn('engagement', 'Engagement', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $ids = $this->allowList->analyticsScope($context->user);
        $rows = [];

        foreach ($this->performance->campaigns($context->fromAt, $context->toAt, $ids, $context->timezone) as $campaign) {
            $rows[] = [
                'campaign' => $campaign['name'] ?? __('No campaign'),
                'posts' => $campaign['posts'],
                'reported' => $campaign['reported'],
                'impressions' => $campaign['totals']['impressions'],
                'reach' => $campaign['totals']['reach'],
                'likes' => $campaign['totals']['likes'],
                'comments' => $campaign['totals']['comments'],
                'shares' => $campaign['totals']['shares'],
                'clicks' => $campaign['totals']['clicks'],
                'engagement' => $campaign['engagement'],
            ];
        }

        return new ReportResult($rows);
    }
}
