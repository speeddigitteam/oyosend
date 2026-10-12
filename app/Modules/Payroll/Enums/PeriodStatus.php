<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PeriodStatus: string
{
    case Open = 'open';
    case Processing = 'processing';
    case Approved = 'approved';
    case Paid = 'paid';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
