<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Enums;

enum SyncState: string
{
    case Ok = 'ok';
    case Partial = 'partial';
    case Throttled = 'throttled';
    case Error = 'error';
}
