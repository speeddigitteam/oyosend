<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use App\Modules\Social\Platforms\Exceptions\PlatformUnavailable;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;

/**
 * One social platform, behind the platform's official API.
 *
 * An implementation must talk to the real API or say it cannot: it never pretends a post was
 * published. Failures are reported by exception ({@see PublishFailed},
 * {@see ReauthorizationRequired}) with a retryable flag that is true only
 * when the request provably was not processed (rate limit, connection refused), because most
 * publishing endpoints have no idempotency key and a blind retry would duplicate the post.
 *
 * No method may include a token in an exception message, log line or return value other than
 * the {@see TokenSet} it is asked to produce.
 */
interface SocialPlatform
{
    /** Stable key stored on accounts: `facebook`, `linkedin`, `x`. */
    public function key(): string;

    public function label(): string;

    public function capabilities(): PlatformCapabilities;

    /** Length of a text as this platform counts it (compare with {@see PlatformCapabilities::$maxText}). */
    public function textLength(string $text): int;

    /** Whether this build + configuration can connect and publish, and if not, why. */
    public function availability(): PlatformAvailability;

    /** The URL to send the person to, plus any secret the callback will need (PKCE verifier). */
    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest;

    /**
     * Exchange the authorization code for tokens and list the profiles/pages it grants.
     *
     * @return list<ProfileConnection>
     *
     * @throws ReauthorizationRequired when the platform rejects the code
     * @throws PublishFailed when the platform cannot be reached
     * @throws PlatformUnavailable when the platform cannot be used
     */
    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array;

    /**
     * Get a new access token from a refresh token.
     *
     * @throws ReauthorizationRequired when the refresh token is invalid, expired or revoked
     * @throws PublishFailed when the platform cannot be reached
     * @throws PlatformUnavailable when the platform cannot be used
     */
    public function refresh(string $refreshToken): TokenSet;

    /**
     * Publish now. The platform has no notion of "later": scheduling is done by this application.
     *
     * @throws PublishFailed
     * @throws PlatformUnavailable
     */
    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost;

    /**
     * The engagement figures the platform returns for one published item, or null when the
     * platform returned nothing. Only called when {@see PlatformCapabilities::$analytics}.
     *
     * @throws PublishFailed
     * @throws ReauthorizationRequired
     * @throws PlatformUnavailable
     */
    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot;
}
