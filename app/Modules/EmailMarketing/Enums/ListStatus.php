<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

enum ListStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
