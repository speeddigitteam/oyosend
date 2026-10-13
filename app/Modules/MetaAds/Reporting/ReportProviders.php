<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Meta Ads publishes to Reports & Analytics (tagged in `MetaAdsServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        CampaignPerformanceReport::class,
        DailySpendReport::class,
    ];
}
