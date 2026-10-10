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
 *    profile, password, second factor and language stay untouched and unseen,
 *    and it cannot hop to another workspace that person belongs to.
 *  - However it is left, its end is recorded.
 */
final class EnforceSupportSession
{
    /**
     * Routes that belong to the impersonated person rather than to the
     * workspace. The pages are closed as well as the actions: the security
     * page shows the secret of a two-step enrolment that is under way.
     */
    private const array PERSONAL = [
        'core.settings.profile',
        'core.settings.profile.*',
        'core.settings.security',
        'core.settings.password.*',
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

        // An ordinary sign-out ends the support session like its own "end" button
        // does, so that the workspace's trail says when the operator left.
        if ($request->routeIs('auth.logout')) {
            $this->impersonation->end($request);

            return redirect()->route('auth.login');
        }

        abort_if($request->routeIs(...self::PERSONAL), 403);

        return $next($request);
    }
}
