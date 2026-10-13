<?php

declare(strict_types=1);

namespace App\Core\Crm\Data;

use App\Core\Crm\Contracts\LeadIntake;

/** How a {@see LeadIntake::capture()} call ended. */
enum LeadCaptureStatus: string
{
    /** The capture was processed now: a contact and a lead exist (new or reused). */
    case Captured = 'captured';

    /** The same source and idempotency key was processed before; the stored result is returned unchanged. */
    case Replayed = 'replayed';

    /** The workspace has no CRM (not installed or not entitled): nothing was written. */
    case Unavailable = 'unavailable';
}
