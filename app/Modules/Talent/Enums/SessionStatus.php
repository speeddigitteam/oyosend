<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** State of a training session. */
enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
