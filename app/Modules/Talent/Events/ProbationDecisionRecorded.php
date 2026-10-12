<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A probation decision was recorded (HRM changes the employee status). */
final class ProbationDecisionRecorded extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.probation.decision_recorded';
    }
}
