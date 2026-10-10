<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum ActivityPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
