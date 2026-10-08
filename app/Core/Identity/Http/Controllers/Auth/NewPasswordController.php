<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Auth;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class NewPasswordController
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Core/Pages/Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $input = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('users')->reset(
            [
                'email' => $input['email'],
                'password' => $input['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $input['token'],
            ],
            function (User $user, string $password) use ($audit): void {
                // Rotating the remember token signs the account out everywhere else.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $audit->record('auth.password_reset', $user, actor: $user);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => trans($status)]);
        }

        Toast::success(__('Your password has been reset. You can sign in now.'));

        return redirect()->route('auth.login');
    }
}
