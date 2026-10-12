<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum Gender: string
{
    case Female = 'female';
    case Male = 'male';
    case Other = 'other';
}
