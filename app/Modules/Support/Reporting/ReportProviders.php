<?php

declare(strict_types=1);

namespace App\Modules\Support\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Support publishes to Reports & Analytics (tagged in `SupportServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        TicketVolumeReport::class,
        SlaPerformanceReport::class,
        CsatReport::class,
    ];
}
