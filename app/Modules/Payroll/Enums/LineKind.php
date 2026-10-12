<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum LineKind: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
    case Reimbursement = 'reimbursement';
    case Employer = 'employer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
