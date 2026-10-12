<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum SuppressionReason: string
{
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Invalid = 'invalid';
    case Manual = 'manual';
}
