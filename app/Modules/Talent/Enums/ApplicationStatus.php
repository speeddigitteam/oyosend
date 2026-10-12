<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** Outcome of an application. */
enum ApplicationStatus: string
{
    case Open = 'open';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
