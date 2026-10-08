<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Settings;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\User;
use App\Core\Identity\TwoFactor\TwoFactorService;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityController
{
    public function edit(Request $request, TwoFactorService $twoFactor): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        $pending = $user->two_factor_secret !== null && ! $user->hasEnabledTwoFactor();

        return Inertia::render('Core/Pages/Settings/Security', [
            'twoFactor' => [
                'enabled' => $user->hasEnabledTwoFactor(),
                'pending' => $pending,
                // Enrolment material is only ever sent while enrolment is in progress.
                'qrCode' => $pending ? $twoFactor->qrCodeSvg($user) : null,
                'secret' => $pending ? $user->twoFactorSecret() : null,
                'recoveryCodesRemaining' => $user->hasEnabledTwoFactor() ? count($user->recoveryCodes()) : null,
            ],
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
        ]);
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $input = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user('web');

        $user->update(['password' => $input['password']]);

        // Sign out every other device that still has the old password's session.
        Auth::guard('web')->logoutOtherDevices($input['password']);

        $audit->record('auth.password_changed', $user);

        Toast::success(__('Password updated.'));

        return back();
    }
}
