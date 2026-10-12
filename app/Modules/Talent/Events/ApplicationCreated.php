<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A candidate was added to a job opening. */
final class ApplicationCreated extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.application.created';
    }
}
