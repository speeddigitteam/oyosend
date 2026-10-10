<?php

declare(strict_types=1);

namespace App\Core\Billing\Enums;

enum SubscriptionStatus: string
{
    /** Using the plan before the first payment is due. */
    case Trialing = 'trialing';

    case Active = 'active';

    /** A payment is overdue; the workspace keeps working until the grace period ends. */
    case PastDue = 'past_due';

    /** Ended because an invoice stayed unpaid past the grace period. */
    case Expired = 'expired';

    /** Ended because the workspace chose to stop. */
    case Canceled = 'canceled';

    /**
     * Whether the workspace may be used. An ended subscription locks it down
     * to billing, where a plan can be taken again.
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Expired, self::Canceled => false,
        };
    }
}
