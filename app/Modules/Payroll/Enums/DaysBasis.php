<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum DaysBasis: string
{
    case CalendarDays = 'calendar_days';
    case FixedDays = 'fixed_days';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
