<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PeriodKind: string
{
    case Monthly = 'monthly';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
