<?php

declare(strict_types=1);

namespace App\Modules\Talent\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Talent publishes to Reports & Analytics (tagged in `TalentServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        RecruitmentFunnelReport::class,
    ];
}
