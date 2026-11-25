<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

/** An event can be more private than its calendar: a private event shows as busy to everyone but its owner and guests. */
enum EventVisibility: string
{
    case Default = 'default';
    case Private = 'private';
}
