<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum RuleBasis: string
{
    case TaxableGross = 'taxable_gross';
    case Gross = 'gross';
    case Basic = 'basic';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
