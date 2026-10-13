<?php

declare(strict_types=1);

describe('response headers', function () {
    it('sends the baseline headers on a central page', function () {
        $response = $this->get('http://localhost/')->assertOk();

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
        expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
        expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('microphone=()');
        // Plain HTTP: no HSTS.
        expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
    });

    it('sends the same headers on the Super Admin and workspace hosts', function () {
        $this->createTenant('acme');

        foreach (['http://admin.localhost/login', 'http://acme.localhost/login'] as $url) {
            $response = $this->get($url);

            expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
            expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
        }
    });

    it('sends HSTS only over HTTPS', function () {
        $response = $this->get('https://localhost/');

        expect($response->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
    });
});

describe('content security policy', function () {
    it('is report-only by default, forbids framing and allows scripts only with a nonce', function () {
        $response = $this->get('http://localhost/');
        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
        expect($policy)
            ->toContain("default-src 'self'")
            ->toContain("frame-ancestors 'none'")
            ->toContain("object-src 'none'")
            ->toContain("base-uri 'self'")
            ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\/=_-]+'/")
            ->not->toContain("script-src 'self' 'unsafe-inline'");
    });

    it('is enforced when configured', function () {
        config(['platform.security.csp' => 'enforce']);

        $response = $this->get('http://localhost/');

        expect($response->headers->has('Content-Security-Policy'))->toBeTrue();
        expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
    });

    it('can be switched off without losing the other headers', function () {
        config(['platform.security.csp' => 'off']);

        $response = $this->get('http://localhost/');

        expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
        expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });
});

describe('session cookie configuration', function () {
    it('is host-only, HttpOnly and SameSite, and secure in production', function () {
        $source = (string) file_get_contents(base_path('config/session.php'));

        expect(config('session.domain'))->toBeNull();
        expect(config('session.http_only'))->toBeTrue();
        expect(config('session.same_site'))->toBe('lax');
        // `secure` falls back to true when APP_ENV is production (config/session.php).
        expect($source)->toContain("env('APP_ENV') === 'production' ? true : null");
    });
});
