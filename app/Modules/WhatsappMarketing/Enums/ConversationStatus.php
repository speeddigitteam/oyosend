<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** Open conversations are in the inbox; a new inbound message reopens a closed one. */
enum ConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
