<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Calendar publishes to Reports & Analytics (tagged in `CalendarServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        WorkspaceEventsReport::class,
    ];
}
