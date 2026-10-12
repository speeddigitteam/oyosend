<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** One row of an application's move history. */
enum ApplicationEvent: string
{
    case Applied = 'applied';
    case Moved = 'moved';
    case Rejected = 'rejected';
    case Hired = 'hired';
    case Withdrawn = 'withdrawn';
    case Reopened = 'reopened';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
