<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** State of a review cycle. */
enum CycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Calibration = 'calibration';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
