<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A job opening was opened for applications. */
final class OpeningPublished extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.opening.published';
    }
}
