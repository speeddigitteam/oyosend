<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Settings;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\User;
use App\Core\Identity\TwoFactor\TwoFactorService;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Two-factor enrolment for the signed-in person. Starting, replacing and
 * removing it all require the current password again.
 */
final class TwoFactorController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->confirmedUser($request);

        if (! $user->hasEnabledTwoFactor()) {
            $this->twoFactor->enable($user);
        }

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:10']]);

        /** @var User $user */
        $user = $request->user('web');

        if (! $this->twoFactor->confirm($user, $input['code'])) {
            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Check your authenticator app and try again.'),
            ]);
        }

        $this->audit->record('auth.two_factor_enabled', $user);

        // Shown once, right now. They are not retrievable afterwards.
        Inertia::flash('recoveryCodes', $user->recoveryCodes());
        Toast::success(__('Two-factor authentication is on.'));

        return back();
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $user = $this->confirmedUser($request);

        abort_unless($user->hasEnabledTwoFactor(), 409);

        Inertia::flash('recoveryCodes', $this->twoFactor->regenerateRecoveryCodes($user));

        $this->audit->record('auth.recovery_codes_regenerated', $user);

        Toast::success(__('New recovery codes generated. The old ones no longer work.'));

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $this->confirmedUser($request);

        $this->twoFactor->disable($user);
        $this->audit->record('auth.two_factor_disabled', $user);

        Toast::success(__('Two-factor authentication is off.'));

        return back();
    }

    private function confirmedUser(Request $request): User
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);

        /** @var User $user */
        $user = $request->user('web');

        return $user;
    }
}
