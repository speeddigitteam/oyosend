<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Auth;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Actions\CompleteWorkspaceLogin;
use App\Core\Identity\Auth\WorkspaceHandoff;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switching workspace without signing in again. See {@see WorkspaceHandoff}
 * for how the session crosses hosts.
 */
final class WorkspaceHandoffController
{
    public function __construct(private readonly WorkspaceHandoff $handoff) {}

    public function issue(Request $request, string $workspace): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        $target = Tenant::query()->where('slug', $workspace)->first();

        if ($target === null || ! $target->isAccessible()) {
            abort(404);
        }

        $isMember = $user->activeMemberships()
            ->where('tenant_id', $target->getKey())
            ->exists();

        abort_unless($isMember, 404);

        // A different origin: tell Inertia to do a full browser visit.
        return Inertia::location($this->handoff->urlFor($user, $target));
    }

    public function consume(
        Request $request,
        TenantContext $context,
        AccessChecker $access,
        CompleteWorkspaceLogin $login,
    ): RedirectResponse {
        $claim = $this->handoff->redeem($request->query('token'));

        if ($claim === null || $claim['tenant_id'] !== $context->requireId()) {
            return redirect()->route('auth.login');
        }

        $user = User::query()->find($claim['user_id']);

        if ($user === null || $access->membership($user) === null) {
            return redirect()->route('auth.login');
        }

        $login->handle($request, $user, false, 'workspace_switch');

        return redirect()->route('core.dashboard');
    }
}
