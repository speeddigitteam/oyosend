<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** A recorded probation decision (HRM changes the employee status itself). */
enum ProbationDecision: string
{
    case Confirm = 'confirm';
    case Extend = 'extend';
    case End = 'end';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
