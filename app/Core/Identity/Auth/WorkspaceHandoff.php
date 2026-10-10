<?php

declare(strict_types=1);

namespace App\Core\Identity\Auth;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Carries a signed-in person to another workspace's host.
 *
 * Sessions are bound to one hostname, so arriving somewhere else signed in
 * needs a handoff: a single-use token, valid for one named user, one named
 * workspace and a few seconds. The target redeems it and starts its own
 * session. Only the token's hash is kept, in the central cache.
 *
 * Issue one only for someone whose identity has just been established on
 * this request (a signed-in member switching workspace, a brand-new account).
 */
final class WorkspaceHandoff
{
    public function __construct(
        private readonly Cache $cache,
        private readonly TenantHost $hosts,
    ) {}

    /**
     * The URL on the target workspace that signs `$user` in there.
     */
    public function urlFor(User $user, Tenant $target): string
    {
        $token = Str::random(64);

        $this->cache->put($this->key($token), [
            'user_id' => $user->getKey(),
            'tenant_id' => $target->getKey(),
        ], (int) config('platform.workspace_handoff_ttl', 60));

        return $this->hosts->url($target, 'auth/handoff?token='.$token);
    }

    /**
     * Redeem a token. It works once; anything but a well-formed token
     * (`?token[]=` included) is simply not a claim.
     *
     * @return array{user_id: string, tenant_id: string}|null
     */
    public function redeem(mixed $token): ?array
    {
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $claim = $this->cache->pull($this->key($token));

        return is_array($claim) && isset($claim['user_id'], $claim['tenant_id']) ? $claim : null;
    }

    private function key(string $token): string
    {
        return 'workspace-handoff:'.hash('sha256', $token);
    }
}
