<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum DealStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
}
