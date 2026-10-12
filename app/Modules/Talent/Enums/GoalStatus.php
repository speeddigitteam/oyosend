<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** State of a goal. */
enum GoalStatus: string
{
    case Active = 'active';
    case Achieved = 'achieved';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
