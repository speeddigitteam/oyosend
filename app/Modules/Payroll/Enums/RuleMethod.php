<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum RuleMethod: string
{
    case Progressive = 'progressive';
    case Banded = 'banded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
