<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
