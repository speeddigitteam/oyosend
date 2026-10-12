<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum ItemStatus: string
{
    case Pending = 'pending';
    case Included = 'included';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
