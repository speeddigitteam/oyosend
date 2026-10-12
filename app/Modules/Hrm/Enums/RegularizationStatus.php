<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum RegularizationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
