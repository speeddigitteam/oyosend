<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A review cycle was launched and its reviews created. */
final class ReviewCycleLaunched extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.review_cycle.launched';
    }
}
