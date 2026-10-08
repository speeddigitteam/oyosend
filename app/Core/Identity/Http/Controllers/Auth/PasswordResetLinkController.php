<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Auth;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordResetLinkController
{
    public function create(): Response
    {
        return Inertia::render('Core/Pages/Auth/ForgotPassword');
    }

    public function store(Request $request, AccessChecker $access): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = User::query()->where('email', $input['email'])->first();

        // Only members of this workspace get a link, but the answer is the
        // same either way so the form cannot be used to probe for accounts.
        if ($user !== null && $access->membership($user) !== null) {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
        }

        Toast::success(__('If that address belongs to this workspace, a reset link is on its way.'));

        return back();
    }
}
