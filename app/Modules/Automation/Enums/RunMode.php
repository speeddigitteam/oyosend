<?php

declare(strict_types=1);

namespace App\Modules\Automation\Enums;

enum RunMode: string
{
    case Live = 'live';
    case DryRun = 'dry_run';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
