<?php

declare(strict_types=1);

namespace App\Core\Crm;

use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\Data\LeadCapture;
use App\Core\Crm\Data\LeadCaptureResult;

/** Bound when no CRM is installed: every capture is honestly `unavailable`. */
final class NullLeadIntake implements LeadIntake
{
    public function capture(LeadCapture $capture): LeadCaptureResult
    {
        return LeadCaptureResult::unavailable();
    }
}
