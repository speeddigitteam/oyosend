<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A job opening was closed. */
final class OpeningClosed extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.opening.closed';
    }
}
