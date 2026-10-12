<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Payroll publishes to Reports & Analytics (tagged in `PayrollServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        RunTotalsReport::class,
    ];
}
