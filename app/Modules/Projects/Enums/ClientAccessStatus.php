<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum ClientAccessStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
