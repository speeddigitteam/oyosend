<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

enum SubscriptionSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Automation = 'automation';
    /** The public sign-up form, after the person confirmed by link (double opt-in). */
    case Form = 'form';
}
