<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A review was calibrated and locked. */
final class ReviewCalibrated extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.review.calibrated';
    }
}
