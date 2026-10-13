<?php

declare(strict_types=1);

namespace App\Core\Crm\Contracts;

use App\Core\Crm\Data\LeadCapture;
use App\Core\Crm\Data\LeadCaptureResult;

/**
 * How another app hands an outside enquiry to the CRM (ADR 0023). The app imports this contract and its data
 * classes, never a CRM model.
 *
 * The active workspace comes from the tenant context. The caller is a system integration, not a person: no
 * permission of a signed-in user is read. With no CRM in the workspace the result is `unavailable` and nothing is
 * written. A capture is idempotent per (workspace, source, idempotency key).
 */
interface LeadIntake
{
    public function capture(LeadCapture $capture): LeadCaptureResult;
}
