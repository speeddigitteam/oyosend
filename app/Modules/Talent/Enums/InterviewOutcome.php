<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** The panel's recorded outcome of an interview. */
enum InterviewOutcome: string
{
    case Advance = 'advance';
    case Hold = 'hold';
    case Reject = 'reject';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
