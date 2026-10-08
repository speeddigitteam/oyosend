<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Enums;

enum TenantStatus: string
{
    /** Registered but not yet approved or onboarded. */
    case Pending = 'pending';

    case Active = 'active';

    /** Access is blocked; data is retained. */
    case Suspended = 'suspended';

    public function allowsAccess(): bool
    {
        return $this === self::Active;
    }
}
