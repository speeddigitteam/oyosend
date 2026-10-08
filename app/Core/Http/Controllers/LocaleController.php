<?php

declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Http\Middleware\SetLocale;
use App\Core\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switches the interface language. Remembered in a cookie for guests and on
 * the account for signed-in workspace users.
 */
final class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var array<string, string> $locales */
        $locales = config('platform.locales', []);

        $input = $request->validate([
            'locale' => ['required', Rule::in(array_keys($locales))],
        ]);

        $user = $request->user();

        if ($user instanceof User) {
            $user->update(['locale' => $input['locale']]);
        }

        return back()->withCookie(cookie()->forever(SetLocale::COOKIE, $input['locale']));
    }
}
