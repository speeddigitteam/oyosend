<?php

declare(strict_types=1);

namespace App\Modules\Automation\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Automation publishes to Reports & Analytics (tagged in `AutomationServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        RunOutcomesReport::class,
    ];
}
