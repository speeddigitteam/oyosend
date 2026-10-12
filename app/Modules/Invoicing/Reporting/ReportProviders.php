<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Invoicing publishes to Reports & Analytics (tagged in `InvoicingServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        ReceivablesAgeingReport::class,
        RevenueReport::class,
    ];
}
