<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** Who authored a message. */
enum SenderType: string
{
    case Customer = 'customer';
    case Agent = 'agent';
    case Bot = 'bot';
    case Campaign = 'campaign';
    case Automation = 'automation';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
