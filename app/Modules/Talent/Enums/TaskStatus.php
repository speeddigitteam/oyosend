<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** State of a checklist task. */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Skipped = 'skipped';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
