<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** One recipient of a campaign. Ranked states only move forward; `failed` and `blocked` are terminal. */
enum RecipientState: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Skipped = 'skipped';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Progress rank of the states that move forward; terminal states are 0. */
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
