<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Enums;

/**
 * Where one recipient of a campaign is.
 *
 * `Pending` waits for the dispatcher (or for its retry time). `Queued` was handed to the communication
 * platform and has no outcome yet. `Blocked` was not sent because there is no consent or the address is
 * suppressed; `Skipped` was not sent because this app could not build a message for it (a number that is not
 * valid, a message that is too long).
 */
enum RecipientStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Skipped = 'skipped';

    /** No further movement is expected from the dispatcher. `Sent` may still become `Delivered`. */
    public function isSettled(): bool
    {
        return ! in_array($this, [self::Pending, self::Queued], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
