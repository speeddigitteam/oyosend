<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum ApprovalStep: string
{
    case Manager = 'manager';
    case Hr = 'hr';
}
