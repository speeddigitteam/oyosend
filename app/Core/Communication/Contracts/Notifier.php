<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\NotificationMessage;

/**
 * Tells workspace members something, in the notification center and (by their preference) by email.
 *
 * This is the only thing another module needs to import to notify people. The implementation belongs to the
 * `communication` module and applies, on the server, the workspace's entitlement and every member's preferences.
 * When the workspace has no communication app the call is a no-op that returns 0: callers must never depend on
 * a notification having been recorded.
 *
 * Must be called inside the active tenant context. Members are tenant memberships (never `User` ids).
 */
interface Notifier
{
    /**
     * @param  list<string>  $membershipIds  Memberships of the ACTIVE workspace; others are ignored.
     * @return int How many members the notification was recorded for (in the center or by email).
     */
    public function notify(array $membershipIds, NotificationMessage $message): int;
}
