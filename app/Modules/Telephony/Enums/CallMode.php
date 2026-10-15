<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Enums;

/** How a call was carried: the browser softphone, or the PBX ringing the member's own phone first. */
enum CallMode: string
{
    case Softphone = 'softphone';
    case Bridge = 'bridge';
}
