<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum MilestoneStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
