<?php

declare(strict_types=1);

namespace App\Modules\Projects\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Projects publishes to Reports & Analytics (tagged in `ProjectsServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        ProjectPerformanceReport::class,
        TimesheetReport::class,
    ];
}
