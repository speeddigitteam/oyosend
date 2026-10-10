<?php

declare(strict_types=1);

namespace App\Core\Billing\Enums;

enum PaymentStatus: string
{
    /** Started at a gateway; nothing has been verified yet. */
    case Pending = 'pending';

    /** Verified with the gateway by the server, or recorded by an operator. */
    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** Abandoned by the payer at the gateway. */
    case Canceled = 'canceled';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
