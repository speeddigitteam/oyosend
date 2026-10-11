<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

enum RecurringStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
