<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Data\MarketingChannel;

/** Bound when no communication module is installed: nothing can be recorded. */
final class NullConsentRecorder implements ConsentRecorder
{
    public function recordOptOut(string $contactId, MarketingChannel $channel, string $method, ?string $evidenceNote = null, ?string $address = null): bool
    {
        return false;
    }

    public function recordOptIn(string $contactId, MarketingChannel $channel, string $method, string $evidence): bool
    {
        return false;
    }
}
