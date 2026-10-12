<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    /** Not sent: the address is suppressed or marketing consent is missing. */
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return ! in_array($this, [self::Queued, self::Sending], true);
    }
}
