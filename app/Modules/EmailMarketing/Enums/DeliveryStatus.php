<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

enum DeliveryStatus: string
{
    /** Planned; waits for its turn in the per-minute budget. */
    case Pending = 'pending';
    /** Held back until the A/B winner is known. */
    case Held = 'held';
    case Sending = 'sending';
    /** Accepted by the platform outbox; not sent yet. */
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    /** The platform refused it: no consent, suppression, unsubscribed. */
    case Blocked = 'blocked';
    /** Never handed over: not subscribed, no address, no unsubscribe link. */
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    /** The outcome is known and will not change by itself (a later bounce can still arrive for `sent`). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Held, self::Sending, self::Queued], true);
    }

    /** Went out of our hands to the mailbox provider. */
    public function wasSent(): bool
    {
        return in_array($this, [self::Sent, self::Delivered], true);
    }
}
