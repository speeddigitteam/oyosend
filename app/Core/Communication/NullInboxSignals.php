<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\InboxSignals;

/**
 * Bound when no communication module is installed in the build: there is no inbox to tell, so nothing is sent.
 */
final class NullInboxSignals implements InboxSignals
{
    public function changed(string $source, string $id): void {}
}
