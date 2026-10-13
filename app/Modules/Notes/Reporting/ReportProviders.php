<?php

declare(strict_types=1);

namespace App\Modules\Notes\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers Notes publishes to Reports & Analytics (tagged in `NotesServiceProvider::register()`). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        NotesByAuthorReport::class,
    ];
}
