<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An interview was completed with an outcome. */
final class InterviewCompleted extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.interview.completed';
    }
}
