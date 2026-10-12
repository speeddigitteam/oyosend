<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A goal or KPI reached its target. */
final class GoalAchieved extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.goal.achieved';
    }
}
