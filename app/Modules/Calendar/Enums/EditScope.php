<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

/** Which occurrences of a recurring event a change or a deletion reaches. */
enum EditScope: string
{
    case This = 'this';
    case Following = 'following';
    case All = 'all';
}
