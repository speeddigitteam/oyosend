<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Realtime\Realtime;
use App\Core\Tenancy\Support\TenantHost;
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
        // The workspace may use its own microphone (the softphone, voice input); the central site and the
        // Super Admin panel never need it. Stateless callback pages (the live-chat frame) set their own.
        $microphone = app(TenantHost::class)->tenantKey($request->getHost()) !== null ? '(self)' : '()';
        $response->headers->set('Permissions-Policy', "camera=(), microphone={$microphone}, geolocation=(), payment=()");

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

        // The website's "find my workspace" form is answered with a redirect to that workspace's sign-in page.
        $workspaces = rtrim(app(TenantHost::class)->url('*', ''), '/');

        $directives = [
            'default-src' => "'self'",
            'script-src' => trim("'self' 'nonce-{$nonce}' {$dev}"),
            // Vue binds styles through the DOM, which a policy does not restrict;
            // 'unsafe-inline' is for the style element of the page-load progress bar.
            'style-src' => trim("'self' 'unsafe-inline' {$dev}"),
            'img-src' => trim("'self' data: blob: ".$this->imageHosts()),
            'font-src' => trim("'self' data: {$dev}"),
            // The realtime websocket (ADR 0035), only when realtime is configured; its origin is validated there.
            'connect-src' => trim("'self' {$dev} {$devSocket} ".(app(Realtime::class)->socketOrigin() ?? '')),
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'frame-ancestors' => "'none'",
            'form-action' => "'self' {$workspaces}",
        ];

        return implode('; ', array_map(
            static fn (string $directive, string $sources): string => "{$directive} {$sources}",
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * The image hosts modules added (`platform.security.img_hosts`): https only, a host name with an optional
     * leading `*.`, nothing that could end the directive or widen it to a scheme or every host.
     */
    private function imageHosts(): string
    {
        $hosts = array_filter(
            (array) config('platform.security.img_hosts', []),
            static fn (mixed $host): bool => is_string($host) && preg_match('~^https://(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+$~', $host) === 1,
        );

        return implode(' ', array_values(array_unique($hosts)));
    }
}
