<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An employee acknowledged their review. */
final class ReviewAcknowledged extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.review.acknowledged';
    }
}
