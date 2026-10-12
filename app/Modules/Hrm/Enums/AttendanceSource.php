<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum AttendanceSource: string
{
    case Clock = 'clock';
    case Manual = 'manual';
    case Regularization = 'regularization';
    case Device = 'device';
}
