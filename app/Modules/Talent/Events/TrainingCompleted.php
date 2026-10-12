<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An employee completed a course. */
final class TrainingCompleted extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.training.completed';
    }
}
