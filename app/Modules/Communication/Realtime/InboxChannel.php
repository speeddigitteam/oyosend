<?php

declare(strict_types=1);

namespace App\Modules\Communication\Realtime;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Core\Realtime\Contracts\RealtimeChannel;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Http\Controllers\InboxController;

/**
 * The workspace's private inbox channel, `private-tenant.{tenantId}.inbox` (ADR 0035). It carries only "conversation
 * X of source Y changed" signals ({@see InboxChanged}).
 *
 * A member may listen when the channel names the workspace of this hostname and they hold `communication.inbox.view`
 * through {@see AccessChecker}: an active member of an active workspace that is entitled to Communication. The
 * signal names a conversation of any source; what the member may then see is decided again by the inbox's own
 * endpoints, which the page asks.
 */
final class InboxChannel implements RealtimeChannel
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessChecker $access,
    ) {}

    /** The channel name (without `private-`) of a workspace. */
    public static function name(string $tenantId): string
    {
        return 'tenant.'.$tenantId.'.inbox';
    }

    public function pattern(): string
    {
        return 'tenant.{tenantId}.inbox';
    }

    public function join(User $user, string $tenantId): bool
    {
        $current = $this->context->id();

        // The workspace comes from the hostname; the channel name only has to agree with it.
        if ($current === null || ! hash_equals($current, $tenantId)) {
            return false;
        }

        return $this->access->allows($user, InboxController::VIEW);
    }
}
