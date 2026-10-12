<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A self or manager review step was submitted. */
final class ReviewSubmitted extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.review.submitted';
    }
}
