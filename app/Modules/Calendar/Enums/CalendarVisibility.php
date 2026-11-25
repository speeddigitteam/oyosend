<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

/** Who else in the workspace may see a personal calendar. A workspace calendar is always visible to everyone. */
enum CalendarVisibility: string
{
    /** Only the owner (and the guests of an event, for that event). */
    case Private = 'private';

    /** Teammates who add the person see when they are busy, never what they are doing. */
    case Busy = 'busy';

    /** Teammates who add the person see the details of every event that is not marked private. */
    case Team = 'team';
}
