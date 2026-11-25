<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

/** Whether an event blocks time. A free event is left out of what busy-only viewers see. */
enum ShowAs: string
{
    case Busy = 'busy';
    case Free = 'free';
}
