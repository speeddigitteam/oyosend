<?php

declare(strict_types=1);

namespace App\Modules\Social\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Social\Actions\SocialAnalytics;

/**
 * Engagement per platform from figures the platforms themselves returned. A figure no platform
 * returned is null (not reported), never zero.
 */
final class EngagementReport implements ReportProvider
{
    private const array FIGURES = ['impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'];

    public function __construct(private readonly SocialAnalytics $analytics) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'social.engagement',
            module: 'social',
            label: 'Social engagement',
            description: 'Impressions, reach, likes, comments, shares and clicks per platform, as reported by the platforms. Empty means the platform did not report the figure.',
            permission: 'social.analytics.view',
            columns: [
                new ReportColumn('platform', 'Platform'),
                new ReportColumn('posts', 'Posts reported', ColumnType::Integer),
                new ReportColumn('impressions', 'Impressions', ColumnType::Integer),
                new ReportColumn('reach', 'Reach', ColumnType::Integer),
                new ReportColumn('likes', 'Likes', ColumnType::Integer),
                new ReportColumn('comments', 'Comments', ColumnType::Integer),
                new ReportColumn('shares', 'Shares', ColumnType::Integer),
                new ReportColumn('clicks', 'Clicks', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $overview = $this->analytics->overview($context->fromAt, $context->toAt);
        $rows = [];

        foreach ($overview['engagement']['byPlatform'] as $platform) {
            $row = ['platform' => $platform['label'], 'posts' => $platform['posts']];

            foreach (self::FIGURES as $figure) {
                $row[$figure] = $platform['totals'][$figure] ?? null;
            }

            $rows[] = $row;
        }

        return new ReportResult($rows);
    }
}
