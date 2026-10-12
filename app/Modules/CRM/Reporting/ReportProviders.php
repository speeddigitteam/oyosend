<?php

declare(strict_types=1);

namespace App\Modules\CRM\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers CRM publishes to Reports & Analytics (tagged in `CRMServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        PipelineReport::class,
        LeadSourceReport::class,
        SalesConversionReport::class,
    ];
}
