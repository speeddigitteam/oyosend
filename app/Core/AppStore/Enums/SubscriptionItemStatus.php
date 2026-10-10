<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

enum SubscriptionItemStatus: string
{
    case Pending = 'pending';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function grantsLicense(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }
}
