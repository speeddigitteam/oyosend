<?php

declare(strict_types=1);

namespace App\Core\Website\Http\Middleware;

use App\Core\Http\Middleware\SetLocale;
use App\Core\Website\SiteUrls;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * A website page is in the language of its address (`/pricing` English,
 * `/bn/pricing` Bengali), whatever the visitor chose before: a search engine
 * must get the same page every time. The choice is then remembered, so the
 * sign-up pages that follow speak the language the visitor was reading.
 */
final class SetSiteLocale
{
    public function handle(Request $request, Closure $next, string $locale = SiteUrls::DEFAULT_LOCALE): Response
    {
        App::setLocale($locale);

        $response = $next($request);

        if ($request->cookie(SetLocale::COOKIE) !== $locale && method_exists($response, 'withCookie')) {
            $response->withCookie(cookie()->forever(SetLocale::COOKIE, $locale));
        }

        return $response;
    }
}
