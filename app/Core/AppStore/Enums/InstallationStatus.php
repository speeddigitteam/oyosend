<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

enum InstallationStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case Inactive = 'inactive';
    case Uninstalled = 'uninstalled';
}
