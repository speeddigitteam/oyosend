<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PaymentMethod: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case MobileWallet = 'mobile_wallet';
    case Cheque = 'cheque';
    case Other = 'other';

    /** English label; pass through `__()` where it is shown. */
    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank transfer',
            self::Cash => 'Cash',
            self::MobileWallet => 'Mobile wallet',
            self::Cheque => 'Cheque',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
