<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

enum AccountStatus: string
{
    case Connected = 'connected';
    case NeedsReauthorization = 'needs_reauthorization';
    case Disconnected = 'disconnected';

    /** The account still holds a seat under the plan's connected-account limit. */
    public function countsTowardLimit(): bool
    {
        return $this !== self::Disconnected;
    }
}
