<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

enum AssignmentMode: string
{
    case Manual = 'manual';
    case RoundRobin = 'round_robin';
}
