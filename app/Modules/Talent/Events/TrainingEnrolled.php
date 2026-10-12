<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An employee was enrolled in a course. */
final class TrainingEnrolled extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.training.enrolled';
    }
}
