<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use Carbon\CarbonImmutable;

/**
 * Reads Page-level insights for one platform. Implemented only against documented calls; an
 * implementation never logs or returns a token, never maps a missing figure to zero and reports
 * a failed read through the snapshot instead of throwing.
 */
interface PageInsightsProvider
{
    /** The platform key (`facebook`) this provider reads for. */
    public function platform(): string;

    /** The timezone the platform buckets its days in. */
    public function dayTimezone(): string;

    /**
     * Daily figures for the platform days `$firstDay` to `$lastDay` (inclusive; only the date part counts).
     */
    public function daily(AccountCredentials $account, CarbonImmutable $firstDay, CarbonImmutable $lastDay): PageInsightsSnapshot;

    /** Permissions the token holds and the tasks of its holder on the Page. */
    public function access(AccountCredentials $account): PageAccessReport;
}
