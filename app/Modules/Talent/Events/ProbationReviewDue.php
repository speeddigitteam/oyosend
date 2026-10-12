<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A probation period ends soon and has no decision yet. */
final class ProbationReviewDue extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.probation.review_due';
    }
}
