<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** State of a probation period. */
enum ProbationStatus: string
{
    case InProgress = 'in_progress';
    case Confirmed = 'confirmed';
    case Ended = 'ended';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
