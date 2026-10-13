<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Enums;

enum ChangeStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
}
