<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum SprintStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
