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
use App\Modules\Social\Models\PageInsight;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\PageInsightsProviders;

/**
 * Page-level insights per account for the period, from figures the platform returned. A flow
 * figure is the sum of the days that reported it (empty = no day reported it); followers and
 * page likes are the latest value in the period. Accounts the viewer is not allowed to see
 * analytics for are left out.
 */
final class PageInsightsReport implements ReportProvider
{
    private const array FLOWS = [
        'new_followers', 'unfollows', 'impressions', 'reach', 'engaged_users', 'post_engagements', 'page_views', 'reactions', 'video_views',
    ];

    public function __construct(
        private readonly PageInsightsProviders $providers,
        private readonly AccountAllowList $allowList,
    ) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'social.page_insights',
            module: 'social',
            label: 'Social page insights',
            description: 'Page-level followers, reach, impressions and engagement per connected page, as returned by the platform. Empty means the platform did not report the figure; days are the platform\'s own days.',
            permission: 'social.analytics.view',
            columns: [
                new ReportColumn('page', 'Page'),
                new ReportColumn('platform', 'Platform'),
                new ReportColumn('days', 'Days reported', ColumnType::Integer),
                new ReportColumn('followers', 'Followers', ColumnType::Integer),
                new ReportColumn('fans', 'Page likes', ColumnType::Integer),
                new ReportColumn('new_followers', 'New followers', ColumnType::Integer),
                new ReportColumn('unfollows', 'Unfollows', ColumnType::Integer),
                new ReportColumn('impressions', 'Impressions', ColumnType::Integer),
                new ReportColumn('reach', 'Reach (daily sum)', ColumnType::Integer),
                new ReportColumn('engaged_users', 'Engaged users', ColumnType::Integer),
                new ReportColumn('post_engagements', 'Post engagements', ColumnType::Integer),
                new ReportColumn('page_views', 'Page views', ColumnType::Integer),
                new ReportColumn('reactions', 'Reactions', ColumnType::Integer),
                new ReportColumn('video_views', 'Video views', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $platforms = $this->providers->platforms();

        if ($platforms === []) {
            return new ReportResult([]);
        }

        $accounts = SocialAccount::query()->whereIn('platform', $platforms)->orderBy('name')->get();
        $allowed = $this->allowList->allowedIds($context->user, AccountAllowList::ANALYTICS, $accounts->pluck('id')->all());
        $accounts = $accounts->filter(static fn (SocialAccount $account): bool => in_array($account->id, $allowed, true));

        if ($accounts->isEmpty()) {
            return new ReportResult([]);
        }

        $byAccount = PageInsight::query()
            ->whereIn('account_id', $accounts->pluck('id')->all())
            ->whereDate('metric_date', '>=', $context->from)->whereDate('metric_date', '<=', $context->to)
            ->orderBy('metric_date')
            ->get()
            ->groupBy('account_id');

        $rows = [];

        foreach ($accounts as $account) {
            $days = $byAccount->get($account->id);

            if ($days === null || $days->isEmpty()) {
                continue;
            }

            $row = ['page' => $account->name, 'platform' => $account->platform, 'days' => $days->count()];

            foreach (['followers', 'fans'] as $level) {
                $latest = $days->last(static fn (PageInsight $day): bool => $day->{$level} !== null);
                $row[$level] = $latest?->{$level};
            }

            foreach (self::FLOWS as $figure) {
                $reported = $days->filter(static fn (PageInsight $day): bool => $day->{$figure} !== null);
                $row[$figure] = $reported->isEmpty() ? null : (int) $reported->sum($figure);
            }

            $rows[] = $row;

            if (count($rows) > $context->rowLimit) {
                break;
            }
        }

        return new ReportResult($rows);
    }
}
