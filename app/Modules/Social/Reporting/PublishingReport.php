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

/** Posts published and failed per platform in the period, from this application's own publishing records. */
final class PublishingReport implements ReportProvider
{
    public function __construct(private readonly SocialAnalytics $analytics) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'social.publishing',
            module: 'social',
            label: 'Social publishing',
            description: 'Posts published and failed per platform in the period.',
            permission: 'social.analytics.view',
            columns: [
                new ReportColumn('platform', 'Platform'),
                new ReportColumn('published', 'Published', ColumnType::Integer),
                new ReportColumn('failed', 'Failed', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $overview = $this->analytics->overview($context->fromAt, $context->toAt);

        return new ReportResult(array_map(static fn (array $row): array => [
            'platform' => $row['label'],
            'published' => $row['published'],
            'failed' => $row['failed'],
        ], $overview['publishing']['byPlatform']));
    }
}
