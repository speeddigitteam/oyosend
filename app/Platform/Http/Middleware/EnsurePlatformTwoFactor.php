<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory for the Super Admin panel: an
 * account without it can sign in only as far as the enrolment screen.
 */
final class EnsurePlatformTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('platform');

        if (
            $user instanceof PlatformUser
            && config('platform.two_factor.required_for_platform')
            && ! $user->hasEnabledTwoFactor()
        ) {
            return redirect()->route('platform.two-factor.setup');
        }

        return $next($request);
    }
}
