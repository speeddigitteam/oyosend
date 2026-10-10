<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

enum InstallationDeactivationReason: string
{
    case Manual = 'manual';
    case LicenceExpired = 'licence_expired';
}
