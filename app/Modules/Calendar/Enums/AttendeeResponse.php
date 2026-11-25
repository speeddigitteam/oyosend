<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

enum AttendeeResponse: string
{
    case NeedsAction = 'needs_action';
    case Accepted = 'accepted';
    case Tentative = 'tentative';
    case Declined = 'declined';
}
