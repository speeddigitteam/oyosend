<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum LeaveAccrual: string
{
    case Annual = 'annual';
    case Monthly = 'monthly';
}
