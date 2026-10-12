<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

/** Where a notification to a workspace member can be delivered. */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
}
