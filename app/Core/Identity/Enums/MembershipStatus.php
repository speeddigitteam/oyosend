<?php

declare(strict_types=1);

namespace App\Core\Identity\Enums;

enum MembershipStatus: string
{
    case Active = 'active';

    /** Kept on record, but the person cannot sign in to this workspace. */
    case Suspended = 'suspended';
}
