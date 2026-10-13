<?php

declare(strict_types=1);

namespace App\Core\Social;

use App\Core\Social\Contracts\ConnectedPages;

/** Bound when no Social app is installed: honestly unavailable, and no Pages. */
final class NullConnectedPages implements ConnectedPages
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function pages(): array
    {
        return [];
    }
}
