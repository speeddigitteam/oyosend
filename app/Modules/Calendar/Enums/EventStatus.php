<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

/** A cancelled row only exists as an exception that removes one occurrence of a recurring series. */
enum EventStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
