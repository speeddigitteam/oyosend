<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum ActivityType: string
{
    case Task = 'task';
    case Meeting = 'meeting';
    case Call = 'call';
    case FollowUp = 'follow_up';
}
