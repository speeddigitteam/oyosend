<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Auth;

use App\Core\Identity\Auth\PendingTwoFactorLogin;
use App\Core\Identity\Auth\TwoFactorChallenge;
use App\Platform\Actions\CompletePlatformLogin;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TwoFactorChallengeController
{
    private const string GUARD = 'platform';

    public function create(Request $request, PendingTwoFactorLogin $pending): Response|RedirectResponse
    {
        if (! $pending->exists($request, self::GUARD)) {
            return redirect()->route('platform.login');
        }

        return Inertia::render('Platform/Pages/Auth/TwoFactorChallenge');
    }

    public function store(
        Request $request,
        PendingTwoFactorLogin $pending,
        TwoFactorChallenge $challenge,
        CompletePlatformLogin $complete,
    ): RedirectResponse {
        $input = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:40', 'required_without:code'],
        ]);

        $user = $pending->user($request, self::GUARD);

        if (! $user instanceof PlatformUser) {
            $pending->clear($request, self::GUARD);

            return redirect()->route('platform.login');
        }

        $method = $challenge->attempt($user, $input['code'] ?? null, $input['recovery_code'] ?? null);

        if ($method === null) {
            if (! $pending->recordFailure($request, self::GUARD)) {
                return redirect()->route('platform.login')->withErrors([
                    'email' => __('Too many incorrect codes. Please sign in again.'),
                ]);
            }

            throw ValidationException::withMessages([
                isset($input['recovery_code']) ? 'recovery_code' : 'code' => $challenge->failureMessage($user, isset($input['recovery_code'])),
            ]);
        }

        $pending->clear($request, self::GUARD);

        $complete->handle($request, $user, false, $method);

        return redirect()->intended(route('platform.dashboard'));
    }
}
