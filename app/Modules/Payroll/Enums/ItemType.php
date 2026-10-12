<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum ItemType: string
{
    case Allowance = 'allowance';
    case Bonus = 'bonus';
    case FestivalBonus = 'festival_bonus';
    case Deduction = 'deduction';
    case Reimbursement = 'reimbursement';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
