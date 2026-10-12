<?php

declare(strict_types=1);

namespace App\Core\Reporting\Contracts;

use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;

/**
 * One report a module offers to the Reports & Analytics app.
 *
 * A module registers providers from its own service provider by tagging the classes
 * (`$this->app->tag([MyReport::class], ReportProvider::TAG)`) and imports nothing of the
 * `reports` module. The app runs a provider only when the workspace is entitled to
 * `definition()->module` and the viewer holds `definition()->permission` (checked by
 * `ReportRegistry` against the database, never against the request).
 *
 * A provider is a thin, READ-ONLY wrapper over the module's own read model:
 *
 *  - it never changes data and never runs SQL taken from the request;
 *  - it returns only columns it declared, and uses only filters it declared;
 *  - when its permission is scopable it narrows records with `RecordAccess` for `$context->user`;
 *  - money is integer minor units beside a currency column, never formatted;
 *  - "not reported" is `null`, not zero.
 *
 * @see docs/REPORTS.md
 */
interface ReportProvider
{
    /** Container tag under which providers are registered. */
    public const string TAG = 'reporting.providers';

    public function definition(): ReportDefinition;

    /**
     * Rows for the validated context, keyed by column key (all declared columns; the runner
     * keeps the ones the viewer chose). Return at most `$context->rowLimit + 1` rows.
     */
    public function run(ReportContext $context): ReportResult;
}
