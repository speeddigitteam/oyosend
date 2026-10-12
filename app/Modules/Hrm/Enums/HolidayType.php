<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum HolidayType: string
{
    case Public = 'public';
    case Optional = 'optional';
}
