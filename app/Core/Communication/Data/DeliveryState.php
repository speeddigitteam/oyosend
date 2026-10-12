<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** Where a message is in the outbox, in terms a marketing app can store. */
enum DeliveryState: string
{
    /** Not recorded: the app/channel is unavailable or the contact has no address. Nothing will be sent. */
    case Unavailable = 'unavailable';
    case Queued = 'queued';
    case Sending = 'sending';
    /** Accepted by the provider. */
    case Sent = 'sent';
    /** The provider confirmed delivery (webhook). */
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    /** Not sent because of suppression or missing consent. Recorded in the log. */
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return ! in_array($this, [self::Queued, self::Sending], true);
    }
}
