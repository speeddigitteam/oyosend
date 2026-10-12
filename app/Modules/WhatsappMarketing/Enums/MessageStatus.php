<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** A message row of a conversation. Outbound states mirror the outbox; inbound is `received`. */
enum MessageStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Blocked = 'blocked';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
            default => 0,
        };
    }
}
