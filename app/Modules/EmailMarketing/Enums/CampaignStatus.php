<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    /** The audience is being worked out in bounded chunks. */
    case Preparing = 'preparing';
    case Sending = 'sending';
    /** The A/B test cohort was sent; the rest waits for a winner. */
    case AwaitingWinner = 'awaiting_winner';
    case Paused = 'paused';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Preparing, self::Sending, self::AwaitingWinner], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Sent, self::Cancelled], true);
    }
}
