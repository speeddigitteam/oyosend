<?php

declare(strict_types=1);

namespace App\Core\Identity\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The single place a workspace session is created, whichever way the person
 * proved who they are (password, second factor, invitation, handoff).
 */
final class CompleteWorkspaceLogin
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, User $user, bool $remember, string $method): void
    {
        Auth::guard('web')->login($user, $remember);

        // New session id, then bind the session to this workspace.
        $request->session()->regenerate();
        $request->session()->put(EnsureTenantMember::SESSION_KEY, $this->context->requireId());

        $user->timestamps = false;
        $user->forceFill(['last_login_at' => Carbon::now()])->saveQuietly();
        $user->timestamps = true;

        $this->audit->record('auth.login', $user, ['method' => $method], $user);
    }
}
