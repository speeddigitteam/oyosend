<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Enums;

/**
 * What the workspace recorded about a sender id's registration with the telecom regulator or operator.
 * It is the workspace's own statement: this app cannot check a registry and never calls one verified.
 */
enum RegistrationStatus: string
{
    case NotRegistered = 'not_registered';
    case Pending = 'pending';
    case Registered = 'registered';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
