<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that tell the browser how far to trust a page.
 *
 *  - no MIME sniffing, no framing by any site, a referrer that never carries
 *    a path (invitation and confirmation links hold a token in theirs);
 *  - HSTS when the request arrived over HTTPS;
 *  - a Content-Security-Policy that allows scripts only from this origin or
 *    carrying this response's nonce.
 *
 * The policy is sent as `Content-Security-Policy-Report-Only` until
 * `platform.security.csp` is set to `enforce`: it has to be watched in a real
 * browser (the console lists every violation) before it is allowed to block.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('platform.security.csp', 'report-only');

        // The nonce has to exist before the view is rendered: @vite and @routes print it.
        $nonce = $mode === 'off' ? null : Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Horizon ships its own inline scripts; its dashboard is behind the Super Admin sign-in.
        if ($nonce !== null && ! $request->is('horizon', 'horizon/*')) {
            $response->headers->set(
                $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
                $this->policy($nonce),
            );
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // While `npm run dev` is running, assets and hot reloading come from the Vite server.
        $dev = Vite::isRunningHot() ? trim((string) @file_get_contents(Vite::hotFile())) : '';
        $devSocket = $dev === '' ? '' : preg_replace('/^http/', 'ws', $dev);

        $directives = [
            'default-src' => "'self'",
            'script-src' => trim("'self' 'nonce-{$nonce}' {$dev}"),
            // Vue binds styles through the DOM, which a policy does not restrict;
            // 'unsafe-inline' is for the style element of the page-load progress bar.
            'style-src' => trim("'self' 'unsafe-inline' {$dev}"),
            'img-src' => "'self' data: blob:",
            'font-src' => trim("'self' data: {$dev}"),
            'connect-src' => trim("'self' {$dev} {$devSocket}"),
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'frame-ancestors' => "'none'",
            'form-action' => "'self'",
        ];

        return implode('; ', array_map(
            static fn (string $directive, string $sources): string => "{$directive} {$sources}",
            array_keys($directives),
            $directives,
        ));
    }
}
