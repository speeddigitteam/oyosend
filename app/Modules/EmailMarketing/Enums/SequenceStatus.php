<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

enum SequenceStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
}
