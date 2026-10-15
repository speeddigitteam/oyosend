<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `throttle` middleware, with one counter per route and limit.
 *
 * The framework keys a plain `throttle:N,M` by the user (or the host and IP) alone, so every route shares one
 * counter: marking conversations read spent the reply budget, and a route limit inside a throttled group (the
 * stateless callbacks) counted each request twice. Without an explicit prefix (the third argument), this keys the
 * counter by the route's name and the limit as well. An explicit prefix still shares a counter on purpose, and a
 * named limiter (`throttle:name`) is unchanged.
 */
final class ThrottleRequestsPerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     * @param  int|string  $maxAttempts
     * @param  float|int  $decayMinutes
     * @param  string  $prefix
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        if (is_string($maxAttempts) && func_num_args() === 3 && $this->limiter->limiter($maxAttempts) !== null) {
            return parent::handle($request, $next, $maxAttempts);
        }

        if ($prefix === '') {
            $route = $request->route();
            $scope = $route === null ? $request->path() : ($route->getName() ?? $route->uri());
            $prefix = $scope.'|'.$maxAttempts.'|'.$decayMinutes.'|';
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
