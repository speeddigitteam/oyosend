<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Auth;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Actions\CompleteWorkspaceLogin;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switching workspace without signing in again.
 *
 * Sessions are bound to one workspace's hostname, so moving to another one
 * needs a handoff: the current workspace issues a single-use token that is
 * valid for one named user, one named target tenant and a few seconds; the
 * target redeems it and starts its own session.
 */
final class WorkspaceHandoffController
{
    private Cache $cache;

    public function __construct()
    {
        // Platform-level: must be readable from the target workspace too.
        $this->cache = app('cache.central');
    }

    public function issue(Request $request, string $workspace, TenantHost $hosts): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        $target = Tenant::query()->where('slug', $workspace)->first();

        if ($target === null || ! $target->isAccessible()) {
            abort(404);
        }

        $isMember = TenantMembership::forTenant($target)
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->exists();

        abort_unless($isMember, 404);

        $token = Str::random(64);

        $this->cache->put($this->key($token), [
            'user_id' => $user->getKey(),
            'tenant_id' => $target->getKey(),
        ], (int) config('platform.workspace_handoff_ttl', 60));

        // A different origin: tell Inertia to do a full browser visit.
        return Inertia::location($hosts->url($target, 'auth/handoff?token='.$token));
    }

    public function consume(
        Request $request,
        TenantContext $context,
        AccessChecker $access,
        CompleteWorkspaceLogin $login,
    ): RedirectResponse {
        $token = (string) $request->query('token', '');

        /** @var array{user_id: string, tenant_id: string}|null $claim */
        $claim = preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1 ? $this->cache->pull($this->key($token)) : null;

        if (! is_array($claim) || $claim['tenant_id'] !== $context->requireId()) {
            return redirect()->route('auth.login');
        }

        $user = User::query()->find($claim['user_id']);

        if ($user === null || $access->membership($user) === null) {
            return redirect()->route('auth.login');
        }

        $login->handle($request, $user, false, 'workspace_switch');

        return redirect()->route('core.dashboard');
    }

    private function key(string $token): string
    {
        return 'workspace-handoff:'.hash('sha256', $token);
    }
}
