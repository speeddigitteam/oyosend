<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts a Super Admin request in the central context and makes `platform` the
 * default guard, so `Auth::user()`, policies and the audit log all see the
 * platform user and never a workspace user.
 */
final class InitializePlatformContext
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenancy->initialized) {
            $this->tenancy->end();
        }

        Auth::shouldUse('platform');

        return $next($request);
    }
}
