<?php

declare(strict_types=1);

namespace App\Core\Realtime\Contracts;

use App\Core\Identity\Models\User;

/**
 * A private realtime channel of a workspace, and who may listen to it (ADR 0035). A module implements it and tags
 * it {@see self::TAG}; the core channel-authorization route registers every tagged channel when a browser asks to
 * subscribe.
 *
 * Every pattern starts with the workspace: `tenant.{tenantId}.…`. `join()` runs on the workspace host, after the
 * session, the membership and the paywall checks, with the tenant taken from the hostname. It must compare the
 * channel's tenant id with the active tenant itself (never trust the name alone) and decide the rest from the
 * database, through `AccessChecker`.
 */
interface RealtimeChannel
{
    public const string TAG = 'core.realtime_channels';

    /** The channel name without the `private-` prefix, with one `{tenantId}` placeholder, e.g. `tenant.{tenantId}.inbox`. */
    public function pattern(): string;

    /** Whether the signed-in member may listen to this workspace's channel. */
    public function join(User $user, string $tenantId): bool;
}
