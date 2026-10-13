<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Enums;

enum ChangeAction: string
{
    case Pause = 'pause';
    case Resume = 'resume';
    case Budget = 'budget';
}
