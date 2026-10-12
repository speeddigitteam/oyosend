<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Stopped = 'stopped';
}
