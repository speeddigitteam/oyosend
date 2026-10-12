<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** Position of one review in the self, manager, calibration, acknowledgement flow. */
enum ReviewStatus: string
{
    case PendingSelf = 'pending_self';
    case PendingManager = 'pending_manager';
    case PendingCalibration = 'pending_calibration';
    case Calibrated = 'calibrated';
    case Acknowledged = 'acknowledged';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
