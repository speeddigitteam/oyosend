<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Auth;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\TwoFactor\TwoFactorService;
use App\Core\Support\Toast;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mandatory two-factor enrolment for platform accounts.
 */
final class TwoFactorSetupController
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): Response|RedirectResponse
    {
        /** @var PlatformUser $user */
        $user = $request->user('platform');

        if ($user->hasEnabledTwoFactor()) {
            return redirect()->route('platform.dashboard');
        }

        if ($user->twoFactorSecret() === null) {
            $this->twoFactor->enable($user);
        }

        return Inertia::render('Platform/Pages/Auth/TwoFactorSetup', [
            'qrCode' => $this->twoFactor->qrCodeSvg($user),
            'secret' => $user->twoFactorSecret(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:10']]);

        /** @var PlatformUser $user */
        $user = $request->user('platform');

        if ($user->hasEnabledTwoFactor()) {
            return redirect()->route('platform.dashboard');
        }

        if (! $this->twoFactor->confirm($user, $input['code'])) {
            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Check your authenticator app and try again.'),
            ]);
        }

        $audit->record('platform.auth.two_factor_enabled', $user);

        // Shown once, on the next page. They are not retrievable afterwards.
        Inertia::flash('recoveryCodes', $user->recoveryCodes());
        Toast::success(__('Two-step verification is on.'));

        return redirect()->route('platform.dashboard');
    }
}
