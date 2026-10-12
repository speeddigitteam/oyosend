<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** Which way a message travelled. */
enum Direction: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
