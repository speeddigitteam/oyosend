<?php

declare(strict_types=1);

namespace App\Modules\Links\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Links publishes to Reports & Analytics (tagged in `LinksServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        LinksByKindReport::class,
    ];
}
