<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum HistoryEvent: string
{
    case Joined = 'joined';
    case StatusChanged = 'status_changed';
    case Updated = 'updated';
    case Terminated = 'terminated';
    case Reinstated = 'reinstated';
    case MembershipLinked = 'membership_linked';
}
