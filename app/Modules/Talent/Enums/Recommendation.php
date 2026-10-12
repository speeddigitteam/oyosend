<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** A panelist's recommendation on a scorecard. */
enum Recommendation: string
{
    case StrongNo = 'strong_no';
    case No = 'no';
    case Yes = 'yes';
    case StrongYes = 'strong_yes';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
