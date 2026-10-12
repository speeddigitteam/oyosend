<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** How keywords are compared with the customer message (case-insensitive). */
enum FlowMatch: string
{
    case Exact = 'exact';
    case Contains = 'contains';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
