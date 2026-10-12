<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** Who opened a 24-hour conversation window. */
enum WindowOrigin: string
{
    case Customer = 'customer';
    case Business = 'business';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
