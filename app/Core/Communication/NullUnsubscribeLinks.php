<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\Data\MarketingChannel;

/** Bound when no communication module is installed: no link can be issued. */
final class NullUnsubscribeLinks implements UnsubscribeLinks
{
    public function issue(string $contactId, MarketingChannel $channel, string $source): ?string
    {
        return null;
    }
}
