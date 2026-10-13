<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

/** What confirming a sign-up link came to. */
enum SignupOutcome: string
{
    /** The member is subscribed to the list with double opt-in evidence. */
    case Subscribed = 'subscribed';
    /** The platform holds a suppression or an opt-out, or the membership bounced: nothing was changed. */
    case Blocked = 'blocked';
    /** The plan's subscriber limit (or a similar refusal): nothing was changed and the link still works. */
    case Unavailable = 'unavailable';
    /** Unknown, used, expired, foreign-tenant or form-disabled link. */
    case Invalid = 'invalid';
}
