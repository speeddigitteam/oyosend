<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum RunKind: string
{
    case Regular = 'regular';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
