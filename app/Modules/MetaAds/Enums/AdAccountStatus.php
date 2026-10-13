<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Enums;

enum AdAccountStatus: string
{
    /** Listed by Meta for the authorising person; not used yet. */
    case Available = 'available';
    /** Synced on a schedule; counts against the plan. */
    case Tracked = 'tracked';
    /** No longer synced; its history is kept and does not count. */
    case Removed = 'removed';

    public function countsTowardLimit(): bool
    {
        return $this === self::Tracked;
    }
}
