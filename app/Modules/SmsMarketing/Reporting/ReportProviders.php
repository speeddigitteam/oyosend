<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers SMS Marketing publishes to Reports & Analytics (tagged in its service provider). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        CampaignsReport::class,
        DailyReport::class,
    ];
}
