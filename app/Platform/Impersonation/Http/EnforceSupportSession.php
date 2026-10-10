<?php

declare(strict_types=1);

namespace App\Platform\Impersonation\Http;

use App\Platform\Impersonation\ImpersonationManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a support session inside its limits. Does nothing for an ordinary
 * workspace session.
 *
 *  - The session ends by itself when its time is up.
 *  - It may work in the workspace, not on the person it signs in as: their
 *    profile, password, second factor and language stay untouched, and it
 *    cannot hop to another workspace that person belongs to.
 */
final class EnforceSupportSession
{
    /** Routes that act on the impersonated person rather than on the workspace. */
    private const array PERSONAL = [
        'core.settings.profile.update',
        'core.settings.password.update',
        'core.settings.two-factor.*',
        'core.locale.update',
        'core.workspaces.switch',
    ];

    public function __construct(private readonly ImpersonationManager $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = ImpersonationManager::of($request);

        if ($session === null) {
            return $next($request);
        }

        if ($session['expires_at'] <= Carbon::now()->getTimestamp()) {
            $this->impersonation->end($request, expired: true);

            return redirect()->route('auth.login');
        }

        abort_if($request->routeIs(...self::PERSONAL), 403);

        return $next($request);
    }
}
