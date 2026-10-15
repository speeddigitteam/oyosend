<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Enums;

enum CallDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
