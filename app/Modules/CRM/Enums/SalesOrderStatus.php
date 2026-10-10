<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum SalesOrderStatus: string
{
    case Confirmed = 'confirmed';
    case Fulfilled = 'fulfilled';
    case Canceled = 'canceled';
}
