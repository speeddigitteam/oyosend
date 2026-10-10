<?php

declare(strict_types=1);

namespace App\Core\Settings\Enums;

enum RegistrationMode: string
{
    /** A confirmed sign-up gets a working workspace straight away. */
    case Open = 'open';

    /** A confirmed sign-up waits until a Super Admin approves it. */
    case Approval = 'approval';

    /** No sign-up: workspaces are created from the Super Admin panel only. */
    case Closed = 'closed';
}
