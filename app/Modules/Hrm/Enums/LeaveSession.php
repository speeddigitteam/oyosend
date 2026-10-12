<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum LeaveSession: string
{
    case Full = 'full';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';
}
