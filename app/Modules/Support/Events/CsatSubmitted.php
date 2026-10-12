<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A requester answered a satisfaction survey. */
final class CsatSubmitted extends SupportEvent
{
    public static function name(): string
    {
        return 'support.csat.submitted';
    }
}
