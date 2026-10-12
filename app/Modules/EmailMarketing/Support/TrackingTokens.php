<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;

/**
 * Signed, stateless tokens for the two public links an email carries: a click redirect and the web version.
 *
 * A token is `base64url(json).base64url(hmac)`. The HMAC key is derived from the application key, so only this
 * server can mint one; the destination of a click is inside the signed payload, which is why the redirect can
 * never be used as an open redirect. The token names the tenant, and the route resolves the tenant from the host:
 * a token for another workspace is rejected like any invalid one.
 */
final class TrackingTokens
{
    public const string CLICK = 'c';

    public const string VIEW = 'v';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantHost $hosts,
    ) {}

    public function clickUrl(string $deliveryId, string $destination): string
    {
        return $this->url($this->sign([self::CLICK, $this->context->requireId(), $deliveryId, $destination]), 'click');
    }

    public function viewUrl(string $deliveryId): string
    {
        return $this->url($this->sign([self::VIEW, $this->context->requireId(), $deliveryId, '']), 'view');
    }

    /**
     * @return array{kind: string, delivery: string, url: string}|null
     */
    public function verify(string $token, string $expectedKind): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || strlen($token) > 1700) {
            return null;
        }

        $payload = $this->decode($parts[0]);
        $signature = $this->decode($parts[1]);

        if ($payload === null || $signature === null || ! hash_equals($this->mac($payload), $signature)) {
            return null;
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || count($data) !== 4 || ! is_string($data[0]) || ! is_string($data[1]) || ! is_string($data[2]) || ! is_string($data[3])) {
            return null;
        }

        if ($data[0] !== $expectedKind || ! $this->context->check() || $data[1] !== $this->context->id()) {
            return null;
        }

        if ($expectedKind === self::CLICK && ! SafeUrl::isAllowed($data[3])) {
            return null;
        }

        return ['kind' => $data[0], 'delivery' => $data[2], 'url' => $data[3]];
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string}  $data */
    private function sign(array $data): string
    {
        $payload = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->encode($payload).'.'.$this->encode($this->mac($payload));
    }

    private function url(string $token, string $kind): string
    {
        $prefix = $kind === 'click' ? 'email-marketing/t/c/' : 'email-marketing/v/';

        return $this->hosts->url($this->context->require(), $prefix.$token);
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, 'email_marketing|'.(string) config('app.key'), true);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
