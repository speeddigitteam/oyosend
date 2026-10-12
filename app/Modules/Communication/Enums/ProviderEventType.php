<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum ProviderEventType: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Inbound = 'inbound';
}
