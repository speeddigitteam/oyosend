<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

/**
 * The state of one membership of a list. Only `subscribed` can ever be sent to, and even then the platform's
 * audience decision (consent and suppression) is asked at send time.
 */
enum SubscriptionStatus: string
{
    /** Added without consent evidence: never sent to. */
    case Pending = 'pending';
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Complained = 'complained';

    /** Counts toward the plan's subscriber limit. */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Subscribed], true);
    }

    /** A person (or the mailbox provider) closed this membership; only fresh evidence can reopen it. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Unsubscribed, self::Bounced, self::Complained], true);
    }
}
