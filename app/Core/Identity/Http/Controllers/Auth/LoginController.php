<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Auth;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Actions\CompleteWorkspaceLogin;
use App\Core\Identity\Actions\VerifyWorkspaceCredentials;
use App\Core\Identity\Auth\PendingTwoFactorLogin;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Http\Requests\LoginRequest;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController
{
    public function create(TenantContext $context): Response
    {
        return Inertia::render('Core/Pages/Auth/Login', [
            'workspace' => $context->require()->name,
        ]);
    }

    public function store(
        LoginRequest $request,
        VerifyWorkspaceCredentials $verify,
        PendingTwoFactorLogin $pending,
        CompleteWorkspaceLogin $complete,
    ): RedirectResponse {
        $user = $verify->handle($request->email(), $request->password(), $request->ip());
        $remember = $request->boolean('remember');

        if ($user->hasEnabledTwoFactor()) {
            $pending->start($request, 'web', $user, $remember);

            return redirect()->route('auth.two-factor.challenge');
        }

        $complete->handle($request, $user, $remember, 'password');

        return redirect()->intended(route('core.dashboard'));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record('auth.logout', $request->user('web'));

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(EnsureTenantMember::REMEMBER_COOKIE));

        return redirect()->route('auth.login');
    }
}
