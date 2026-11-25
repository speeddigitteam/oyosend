<?php

declare(strict_types=1);

namespace App\Core\Website\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * The framework's session middleware, except for a first visit to a website page.
 *
 * A visitor without a session cookie who reads a website page (`central.home`, `central.site.*`) gets the page with
 * no session started and no cookie set, so crawlers do not leave a session row behind on every hit and a CDN may keep
 * the page (`config('site.edge_cache_seconds')`). The page's forms still work: a same-origin POST passes the
 * framework's `Sec-Fetch-Site` check without a token, and that POST starts the session. A visitor who already has a
 * session (a form sent back with errors, a "thank you" flash) gets the ordinary stateful page. docs/WEBSITE.md.
 */
final class StartWebsiteSession extends StartSession
{
    public function handle($request, Closure $next)
    {
        if (! $this->sessionConfigured() || ! $this->isFirstVisitToWebsitePage($request)) {
            return parent::handle($request, $next);
        }

        // An empty, never-started store: reading it finds nothing, and nothing is saved.
        $request->setLaravelSession($this->getSession($request));

        /** @var Response $response */
        $response = $next($request);

        // The same page for everyone: drop what the inner middleware set for a session that does not exist.
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        if ($response->isSuccessful()) {
            $seconds = max(0, (int) config('site.edge_cache_seconds'));
            $response->headers->set('Cache-Control', $seconds > 0 ? "public, max-age=0, s-maxage={$seconds}" : 'public, max-age=0');
            $response->setVary('Cookie', false);
        }

        return $response;
    }

    private function isFirstVisitToWebsitePage(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->cookies->has((string) config('session.cookie'))) {
            return false;
        }

        $name = (string) $request->route()?->getName();

        return $name === 'central.home' || str_starts_with($name, 'central.site.');
    }
}
