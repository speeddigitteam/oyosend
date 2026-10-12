<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use App\Modules\Social\Platforms\Exceptions\PlatformUnavailable;

/**
 * A platform this build knows about but cannot honestly drive. It lists the reason and refuses every call;
 * nothing is simulated. Replace it with a real adapter once the platform's app review and an API
 * implementation exist.
 */
final class UnavailablePlatform implements SocialPlatform
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $reason,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities;
    }

    public function textLength(string $text): int
    {
        return mb_strlen($text);
    }

    public function availability(): PlatformAvailability
    {
        return PlatformAvailability::unavailable(PlatformAvailability::NOT_IMPLEMENTED, $this->reason);
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        throw new PlatformUnavailable($this->reason);
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        throw new PlatformUnavailable($this->reason);
    }

    public function refresh(string $refreshToken): TokenSet
    {
        throw new PlatformUnavailable($this->reason);
    }

    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        throw new PlatformUnavailable($this->reason);
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        throw new PlatformUnavailable($this->reason);
    }
}
