<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** What a pipeline stage means. `hired` and `rejected` are the two outcomes. */
enum StageType: string
{
    case Active = 'active';
    case Hired = 'hired';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
