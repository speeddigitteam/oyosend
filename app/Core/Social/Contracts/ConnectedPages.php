<?php

declare(strict_types=1);

namespace App\Core\Social\Contracts;

use App\Core\Social\Data\ConnectedPage;

/**
 * The Facebook Pages the active workspace connected in the Social app (ADR 0027). Read-only and
 * tenant-scoped; it never exposes a token, and it never lets the caller act through a Page.
 *
 * An app that needs its own authorisation (Meta Ads holds its own ad-account token) uses this only to
 * show which Pages exist and to link to them. Core binds a null object; the `social` module rebinds it.
 */
interface ConnectedPages
{
    /** Whether the Social app is available to the workspace. False means "unknown", not "none". */
    public function isAvailable(): bool;

    /** @return list<ConnectedPage> Connected and re-authorisation-needed Pages, by name. */
    public function pages(): array;
}
