<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An interview was scheduled. */
final class InterviewScheduled extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.interview.scheduled';
    }
}
