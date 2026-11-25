<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Settings;

use App\Core\Http\Middleware\SetLocale;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('Core/Pages/Settings/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale ?? app()->getLocale(),
                'numerals' => $user->preference('numerals', 'latn'),
                // Shown only when the workspace offers voice input (ADR 0038); kept across workspaces.
                'voiceAutoRun' => $user->preference('voice_auto_run') === true,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var array<string, string> $locales */
        $locales = config('platform.locales', []);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', Rule::in(array_keys($locales))],
            'numerals' => ['required', Rule::in(['latn', 'beng'])],
            'voice_auto_run' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user('web');

        $user->update([
            'name' => $input['name'],
            'locale' => $input['locale'],
            'preferences' => [
                ...($user->preferences ?? []),
                'numerals' => $input['numerals'],
                'voice_auto_run' => (bool) ($input['voice_auto_run'] ?? $user->preference('voice_auto_run', false)),
            ],
        ]);

        app()->setLocale($input['locale']);

        Toast::success(__('Profile saved.'));

        return back()->withCookie(cookie()->forever(SetLocale::COOKIE, $input['locale']));
    }
}
