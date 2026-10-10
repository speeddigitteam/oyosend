<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

enum EntitlementSource: string
{
    case Free = 'free';
    case Purchase = 'purchase';
    case Trial = 'trial';
    case Manual = 'manual';
}
