<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Telephony publishes to Reports & Analytics (tagged in `TelephonyServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        CallsByMemberReport::class,
    ];
}
