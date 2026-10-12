<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

enum SubscriptionSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Automation = 'automation';
}
