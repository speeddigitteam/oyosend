<?php

declare(strict_types=1);

namespace App\Modules\Social\Support;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Support\Str;

/**
 * The central OAuth redirect broker.
 *
 * Platforms require every redirect URI to be registered, so one URI on the central host
 * (`https://APP_DOMAIN/social/oauth/callback/{platform}`) receives the return and forwards the
 * browser to the workspace that started the flow. The workspace is learned from a signed
 * envelope the workspace itself put in front of the `state` when the flow began; nothing the
 * visitor sends is trusted to choose the target.
 *
 * Platform-visible state: `{slug}.{expiry}.{nonce}.{mac}.{inner}`. `inner` is the existing
 * session-bound random state; the workspace callback still verifies it (the envelope wraps,
 * never replaces, that check). The MAC covers slug, platform, expiry, nonce and inner with a key
 * derived from APP_KEY, so the envelope cannot be forged, re-aimed at another workspace or
 * platform, or have its inner value swapped.
 */
final class OAuthBroker
{
    public const int TTL_SECONDS = 600;

    private const string CONTEXT = 'social.oauth.broker.v1';

    public function __construct(private readonly TenantHost $hosts) {}

    public function enabled(): bool
    {
        return (bool) config('social.redirect_broker', true);
    }

    /** The URI to register with a platform and to send as `redirect_uri`. */
    public function redirectUri(string $platform, string $workspaceUri): string
    {
        if (! $this->enabled()) {
            return $workspaceUri;
        }

        $base = parse_url((string) config('app.url'));
        $scheme = $base['scheme'] ?? 'https';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        return $scheme.'://'.$this->hosts->domain().$port.'/social/oauth/callback/'.$platform;
    }

    /** Wrap the session-bound state for the platform. Returns the plain state when the broker is off. */
    public function wrap(string $tenantSlug, string $platform, string $inner, ?int $now = null): string
    {
        if (! $this->enabled()) {
            return $inner;
        }

        $expires = ($now ?? now()->getTimestamp()) + self::TTL_SECONDS;
        $nonce = Str::lower(Str::random(12));

        return implode('.', [
            $tenantSlug,
            base_convert((string) $expires, 10, 36),
            $nonce,
            $this->mac($tenantSlug, $platform, $expires, $nonce, $inner),
            $inner,
        ]);
    }

    /**
     * Verify an envelope and return its parts, or null for anything wrong.
     *
     * @return array{slug: string, inner: string}|null
     */
    public function unwrap(string $state, string $platform, ?int $now = null): ?array
    {
        $parts = explode('.', $state, 5);

        if (count($parts) !== 5 || strlen($state) > 512) {
            return null;
        }

        [$slug, $expiry36, $nonce, $mac, $inner] = $parts;

        if ($slug === '' || $inner === ''
            || preg_match('/^[0-9a-z]{1,12}$/D', $expiry36) !== 1
            || preg_match('/^[0-9a-z]{12}$/D', $nonce) !== 1) {
            return null;
        }

        $expires = (int) base_convert($expiry36, 36, 10);

        if (! hash_equals($this->mac($slug, $platform, $expires, $nonce, $inner), $mac)) {
            return null;
        }

        $now ??= now()->getTimestamp();

        if ($expires < $now || $expires > $now + self::TTL_SECONDS) {
            return null;
        }

        return ['slug' => $slug, 'inner' => $inner];
    }

    /**
     * The workspace URL to forward to for a verified envelope, or null when the workspace does not
     * exist or is not accessible. The host is built from APP_DOMAIN and a slug that exists; never
     * from request input.
     */
    public function target(string $slug, string $platform): ?string
    {
        if ($this->hosts->tenantKey($slug.'.'.$this->hosts->domain()) !== $slug) {
            return null;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant instanceof Tenant || ! $tenant->isAccessible()) {
            return null;
        }

        return $this->hosts->url($tenant, '/social/accounts/callback/'.$platform);
    }

    private function mac(string $slug, string $platform, int $expires, string $nonce, string $inner): string
    {
        $key = hash_hmac('sha256', self::CONTEXT, (string) config('app.key'), true);
        $payload = implode('|', [$slug, $platform, $expires, $nonce, $inner]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }
}
