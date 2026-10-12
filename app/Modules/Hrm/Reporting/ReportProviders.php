<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers HRM publishes to Reports & Analytics (tagged in `HrmServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        HeadcountReport::class,
        AttendanceReport::class,
        LeaveBalanceReport::class,
    ];
}
