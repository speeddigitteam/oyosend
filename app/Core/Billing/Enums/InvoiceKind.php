<?php

declare(strict_types=1);

namespace App\Core\Billing\Enums;

enum InvoiceKind: string
{
    /** The first period of a plan: after a trial, or when a plan is taken again. */
    case Subscription = 'subscription';

    /** The next period of the same subscription. */
    case Renewal = 'renewal';

    /** Moving to a more expensive plan in the middle of a paid period. */
    case Change = 'change';
}
