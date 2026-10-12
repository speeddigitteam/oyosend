<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum RuleCategory: string
{
    case IncomeTax = 'income_tax';
    case SocialContribution = 'social_contribution';
    case Levy = 'levy';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
