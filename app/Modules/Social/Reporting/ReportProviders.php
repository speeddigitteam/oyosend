<?php

declare(strict_types=1);

namespace App\Modules\Social\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Social publishes to Reports & Analytics (tagged in `SocialServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        PublishingReport::class,
        EngagementReport::class,
        PageInsightsReport::class,
        CampaignPerformanceReport::class,
    ];
}
