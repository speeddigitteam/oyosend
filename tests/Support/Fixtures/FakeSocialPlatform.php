<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\AuthorizationRequest;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PlatformCapabilities;
use App\Modules\Social\Platforms\PostMetricsSnapshot;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\SocialPlatform;
use App\Modules\Social\Platforms\TokenSet;
use Carbon\CarbonImmutable;

/**
 * A scriptable platform for tests only (it lives in tests/, so no build can ship it).
 * It records every call and lets a test queue the failures the next publishes should raise.
 */
final class FakeSocialPlatform implements SocialPlatform
{
    /** @var list<PublishPayload> */
    public array $published = [];

    /** @var list<PublishFailed|PostMetricsSnapshot|null> */
    public array $script = [];

    public int $refreshes = 0;

    public bool $refreshFails = false;

    /** @var list<PostMetricsSnapshot|null> */
    public array $metricsScript = [];

    public bool $available = true;

    /** @var list<ProfileConnection> */
    public array $profiles = [];

    public function __construct(
        private readonly string $key = 'fake',
        private readonly PlatformCapabilities $caps = new PlatformCapabilities(
            publish: true, schedule: true, analytics: true, comments: false, images: true, video: false, maxText: 100, maxMedia: 2,
        ),
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake '.$this->key;
    }

    public function capabilities(): PlatformCapabilities
    {
        return $this->caps;
    }

    public function textLength(string $text): int
    {
        return mb_strlen($text);
    }

    public function availability(): PlatformAvailability
    {
        return $this->available ? PlatformAvailability::available()
            : PlatformAvailability::unavailable(PlatformAvailability::NOT_CONFIGURED, 'Not configured for this test.');
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        return new AuthorizationRequest('https://fake.test/authorize?state='.$state.'&redirect_uri='.urlencode($redirectUri), 'verifier-123');
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        if ($code === 'bad') {
            throw new ReauthorizationRequired('The code was rejected.');
        }

        return $this->profiles !== [] ? $this->profiles : [new ProfileConnection(
            'ext-1', 'Fake Page', '@fake', null, 'page',
            new TokenSet('access-secret-1', 'refresh-secret-1', CarbonImmutable::now()->addDays(30)),
        )];
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $this->refreshes++;

        if ($this->refreshFails) {
            throw new ReauthorizationRequired('The refresh token was revoked.');
        }

        return new TokenSet('access-secret-refreshed', 'refresh-secret-2', CarbonImmutable::now()->addDays(30));
    }

    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        $next = array_shift($this->script);

        if ($next instanceof PublishFailed) {
            throw $next;
        }

        $this->published[] = $payload;
        $id = 'post-'.count($this->published);

        return new PublishedPost($id, 'https://fake.test/p/'.$id);
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        return array_shift($this->metricsScript);
    }
}
