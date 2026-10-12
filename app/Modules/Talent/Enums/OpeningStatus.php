<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** Hiring state of a job opening. */
enum OpeningStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
