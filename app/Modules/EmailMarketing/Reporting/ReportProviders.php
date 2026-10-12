<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Email Marketing publishes to Reports & Analytics (tagged in the service provider). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        CampaignPerformanceReport::class,
        ListHealthReport::class,
    ];
}
