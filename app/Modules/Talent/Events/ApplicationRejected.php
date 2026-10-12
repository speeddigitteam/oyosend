<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An application was rejected. */
final class ApplicationRejected extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.application.rejected';
    }
}
