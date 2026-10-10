<?php

declare(strict_types=1);

namespace App\Core\Billing\Enums;

enum LimitKind: string
{
    /** How many of something exist right now: members, branches. */
    case Gauge = 'gauge';

    /** How much was consumed in the current billing period: messages sent, credits used. */
    case Metered = 'metered';
}
