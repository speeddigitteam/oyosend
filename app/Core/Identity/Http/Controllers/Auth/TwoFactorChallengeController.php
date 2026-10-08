<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Auth;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Actions\CompleteWorkspaceLogin;
use App\Core\Identity\Auth\PendingTwoFactorLogin;
use App\Core\Identity\Auth\TwoFactorChallenge;
use App\Core\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TwoFactorChallengeController
{
    private const string GUARD = 'web';

    public function create(Request $request, PendingTwoFactorLogin $pending): Response|RedirectResponse
    {
        if (! $pending->exists($request, self::GUARD)) {
            return redirect()->route('auth.login');
        }

        return Inertia::render('Core/Pages/Auth/TwoFactorChallenge');
    }

    public function store(
        Request $request,
        PendingTwoFactorLogin $pending,
        TwoFactorChallenge $challenge,
        AccessChecker $access,
        CompleteWorkspaceLogin $complete,
    ): RedirectResponse {
        $input = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:40', 'required_without:code'],
        ]);

        $user = $pending->user($request, self::GUARD);

        // The person may have been removed from the workspace since the password step.
        if (! $user instanceof User || $access->membership($user) === null) {
            $pending->clear($request, self::GUARD);

            return redirect()->route('auth.login');
        }

        $method = $challenge->attempt($user, $input['code'] ?? null, $input['recovery_code'] ?? null);

        if ($method === null) {
            if (! $pending->recordFailure($request, self::GUARD)) {
                return redirect()->route('auth.login')->withErrors([
                    'email' => __('Too many incorrect codes. Please sign in again.'),
                ]);
            }

            throw ValidationException::withMessages([
                isset($input['recovery_code']) ? 'recovery_code' : 'code' => __('That code is not valid. Check your authenticator app and try again.'),
            ]);
        }

        $remember = $pending->remember($request, self::GUARD);
        $pending->clear($request, self::GUARD);

        $complete->handle($request, $user, $remember, $method);

        return redirect()->intended(route('core.dashboard'));
    }
}
