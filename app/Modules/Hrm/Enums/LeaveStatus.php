<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum LeaveStatus: string
{
    case PendingManager = 'pending_manager';
    case PendingHr = 'pending_hr';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Holds dates (and balance) until decided. */
    public function isPending(): bool
    {
        return $this === self::PendingManager || $this === self::PendingHr;
    }

    /** Occupies its dates: no other request may overlap it. */
    public function isLive(): bool
    {
        return $this->isPending() || $this === self::Approved;
    }
}
