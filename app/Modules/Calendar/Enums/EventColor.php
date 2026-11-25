<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

/** The fixed palette for calendars and events; each maps to a `--event-*` design token in both themes. */
enum EventColor: string
{
    case Red = 'red';
    case Pink = 'pink';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Sage = 'sage';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Lavender = 'lavender';
    case Purple = 'purple';
    case Graphite = 'graphite';
}
