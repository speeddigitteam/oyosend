<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum ActivityStatus: string
{
    case Planned = 'planned';
    case Completed = 'completed';
    case Canceled = 'canceled';
}
