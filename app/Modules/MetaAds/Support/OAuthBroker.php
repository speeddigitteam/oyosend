<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Support;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Support\Str;

/**
 * The central OAuth redirect broker for Meta Ads (the same signed-envelope pattern as the Social app's,
 * implemented here because modules never import each other).
 *
 * Meta requires every redirect URI to be registered, so one URI on the central host
 * (`https://APP_DOMAIN/meta-ads/oauth/callback`) receives the return and forwards the browser to the
 * workspace that started the flow. The workspace is learned from a signed envelope the workspace itself
 * put in front of `state`; nothing the visitor sends is trusted to choose the target.
 *
 * Meta-visible state: `{slug}.{expiry}.{nonce}.{mac}.{inner}`. `inner` is the session-bound, single-use
 * random state that the workspace callback still verifies and consumes. The MAC covers slug, flow,
 * expiry, nonce and inner with a key derived from APP_KEY.
 */
final class OAuthBroker
{
    public const int TTL_SECONDS = 600;

    private const string CONTEXT = 'meta_ads.oauth.broker.v1';

    private const string FLOW = 'meta_ads';

    public function __construct(private readonly TenantHost $hosts) {}

    public function enabled(): bool
    {
        return (bool) config('meta_ads.redirect_broker', true);
    }

    /** The URI to register with Meta and to send as `redirect_uri`. */
    public function redirectUri(string $workspaceUri): string
    {
        if (! $this->enabled()) {
            return $workspaceUri;
        }

        $base = parse_url((string) config('app.url'));
        $scheme = $base['scheme'] ?? 'https';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        return $scheme.'://'.$this->hosts->domain().$port.'/meta-ads/oauth/callback';
    }

    /** Wrap the session-bound state. Returns the plain state when the broker is off. */
    public function wrap(string $tenantSlug, string $inner, ?int $now = null): string
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
            $this->mac($tenantSlug, $expires, $nonce, $inner),
            $inner,
        ]);
    }

    /**
     * Verify an envelope and return its parts, or null for anything wrong.
     *
     * @return array{slug: string, inner: string}|null
     */
    public function unwrap(string $state, ?int $now = null): ?array
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

        if (! hash_equals($this->mac($slug, $expires, $nonce, $inner), $mac)) {
            return null;
        }

        $now ??= now()->getTimestamp();

        if ($expires < $now || $expires > $now + self::TTL_SECONDS) {
            return null;
        }

        return ['slug' => $slug, 'inner' => $inner];
    }

    /**
     * The workspace URL to forward to for a verified envelope, or null when the workspace does not exist or
     * is not accessible. Built from APP_DOMAIN and a slug that exists, never from request input.
     */
    public function target(string $slug): ?string
    {
        if ($this->hosts->tenantKey($slug.'.'.$this->hosts->domain()) !== $slug) {
            return null;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant instanceof Tenant || ! $tenant->isAccessible()) {
            return null;
        }

        return $this->hosts->url($tenant, '/meta-ads/accounts/callback');
    }

    private function mac(string $slug, int $expires, string $nonce, string $inner): string
    {
        $key = hash_hmac('sha256', self::CONTEXT, (string) config('app.key'), true);
        $payload = implode('|', [$slug, self::FLOW, $expires, $nonce, $inner]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }
}
