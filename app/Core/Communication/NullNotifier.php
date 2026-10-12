<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\NotificationMessage;

/**
 * Bound when no communication module is installed in the build. It records nothing and says so.
 */
final class NullNotifier implements Notifier
{
    public function notify(array $membershipIds, NotificationMessage $message): int
    {
        return 0;
    }
}
