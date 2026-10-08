<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Auth;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Auth\CredentialVerifier;
use App\Core\Identity\Auth\LoginThrottle;
use App\Core\Identity\Auth\PendingTwoFactorLogin;
use App\Core\Identity\Http\Requests\LoginRequest;
use App\Platform\Actions\CompletePlatformLogin;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController
{
    public function create(): Response
    {
        return Inertia::render('Platform/Pages/Auth/Login');
    }

    public function store(
        LoginRequest $request,
        CredentialVerifier $verifier,
        LoginThrottle $throttle,
        PendingTwoFactorLogin $pending,
        CompletePlatformLogin $complete,
        AuditLogger $audit,
    ): RedirectResponse {
        $key = LoginThrottle::key('platform', $request->email(), $request->ip());

        $throttle->ensureNotLocked($key);

        $user = $verifier->verify('platform', $request->email(), $request->password());

        if (! $user instanceof PlatformUser) {
            $throttle->hit($key);
            $audit->record('platform.auth.login_failed', properties: ['email' => $request->email()]);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $throttle->clear($key);

        if ($user->hasEnabledTwoFactor()) {
            $pending->start($request, 'platform', $user, false);

            return redirect()->route('platform.two-factor.challenge');
        }

        // No second factor yet: the session is created, but EnsurePlatformTwoFactor
        // confines it to the enrolment screen until one is set up.
        $complete->handle($request, $user, false, 'password');

        return redirect()->intended(route('platform.dashboard'));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record('platform.auth.logout', $request->user('platform'));

        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
