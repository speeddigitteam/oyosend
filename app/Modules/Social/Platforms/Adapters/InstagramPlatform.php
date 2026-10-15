<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Adapters;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\AuthorizationRequest;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\Instagram\InstagramGraph;
use App\Modules\Social\Platforms\Instagram\InstagramMediaRules;
use App\Modules\Social\Platforms\Instagram\InstagramPublisher;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PlatformCapabilities;
use App\Modules\Social\Platforms\PostMetricsSnapshot;
use App\Modules\Social\Platforms\PostTypeAware;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\StepwisePublisher;
use App\Modules\Social\Platforms\TokenSet;
use App\Modules\Social\Platforms\ValidatesMedia;
use Closure;
use Illuminate\Http\Client\Response;

/**
 * Instagram professional accounts linked to a Facebook Page, through the Instagram Graph API with Facebook
 * Login (ADR 0033). It reuses the operator's Facebook (Meta) app — client id, secret and API version — and has
 * no credential of its own; the panel switch `social.platforms.instagram.publishing` stays off until Meta has
 * approved `instagram_basic` and `instagram_content_publish` in app review.
 *
 * Documented calls used: `dialog/oauth`, `oauth/access_token` (code and `fb_exchange_token`),
 * `me/accounts?fields=…,instagram_business_account{…}`, `{ig-user}/content_publishing_limit`, `{ig-user}/media`
 * (image, `REELS`, carousel children and `CAROUSEL`), `{container}?fields=status_code`, `{ig-user}/media_publish`,
 * `{media}?fields=permalink` and `{media}?fields=like_count,comments_count`. The publish flow is in
 * {@see InstagramPublisher}. Each connected account acts with its Page's access token, derived from a long-lived
 * user token, so it has no expiry and cannot be refreshed (like Facebook Pages). NOT yet exercised against Meta's
 * live API in this repository.
 */
final class InstagramPlatform extends HttpPlatform implements PostTypeAware, StepwisePublisher, ValidatesMedia
{
    private const array DEFAULT_SCOPES = ['instagram_basic', 'instagram_content_publish', 'pages_show_list', 'pages_read_engagement', 'business_management'];

    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    public function availability(): PlatformAvailability
    {
        if (! $this->switchedOn()) {
            return PlatformAvailability::unavailable(
                PlatformAvailability::NOT_CONFIGURED,
                __('Instagram publishing is switched off. The operator switches it on in Super Admin > Integrations once Meta has approved instagram_content_publish for the Facebook app (app review).'),
            );
        }

        if ($this->facebook('client_id') === null || $this->facebook('client_secret') === null) {
            return PlatformAvailability::unavailable(
                PlatformAvailability::NOT_CONFIGURED,
                __('Instagram publishing uses the Facebook (Meta) app, whose credentials are not configured. The operator must register the app (including its app review) and set its client id and secret.'),
            );
        }

        return PlatformAvailability::available();
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            publish: true, schedule: true, analytics: true, comments: false,
            images: true, video: true,
            maxText: max(1, (int) $this->limit('caption_max', 2200)),
            maxMedia: max(1, (int) $this->limit('carousel_max', 10)),
            requiresMedia: true,
            maxVideoBytes: max(0, (int) $this->limit('reel_max_bytes', 0)),
            maxVideoSeconds: max(0, (int) $this->limit('reel_max_seconds', 0)),
            minVideoSeconds: max(0, (int) $this->limit('reel_min_seconds', 0)),
        );
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        return new AuthorizationRequest('https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->facebook('client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
        ]));
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        $short = $this->send(fn (): Response => $this->client()->asForm()->post($this->graph('oauth/access_token'), [
            'client_id' => $this->facebook('client_id'),
            'client_secret' => $this->facebook('client_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));
        $userToken = $short->successful() ? $short->json('access_token') : null;

        if (! is_string($userToken) || $userToken === '') {
            throw new ReauthorizationRequired($this->clean($short->json('error.message'), __('Facebook did not accept the authorization.')));
        }

        // The long-lived user token makes the Page tokens derived from it non-expiring.
        $long = $this->send(fn (): Response => $this->client()->asForm()->post($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->facebook('client_id'),
            'client_secret' => $this->facebook('client_secret'),
            'fb_exchange_token' => $userToken,
        ]));
        $longToken = $long->successful() ? $long->json('access_token') : null;
        $userToken = is_string($longToken) && $longToken !== '' ? $longToken : $userToken;

        $pages = $this->send(fn (): Response => $this->client()->withToken($userToken)->get($this->graph('me/accounts'), [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
        ]));

        if (! $pages->successful()) {
            throw new ReauthorizationRequired($this->clean($pages->json('error.message'), __('The Facebook Pages could not be listed.')));
        }

        $profiles = [];

        foreach ((array) $pages->json('data', []) as $page) {
            $account = is_array($page) ? ($page['instagram_business_account'] ?? null) : null;

            if (! is_array($account) || ! is_string($page['access_token'] ?? null) || $page['access_token'] === '') {
                continue;
            }

            $id = $account['id'] ?? null;

            if ((! is_string($id) || $id === '') && ! is_int($id)) {
                continue;
            }

            $username = is_string($account['username'] ?? null) && $account['username'] !== '' ? $account['username'] : null;
            $picture = $account['profile_picture_url'] ?? null;
            $profiles[(string) $id] = new ProfileConnection(
                externalId: (string) $id,
                name: $this->clean($account['name'] ?? $username, 'Instagram account'),
                handle: $username,
                avatarUrl: is_string($picture) && str_starts_with($picture, 'https://') ? $picture : null,
                type: 'business_account',
                tokens: new TokenSet($page['access_token'], scopes: $this->scopes()),
            );
        }

        if ($profiles === []) {
            throw new ReauthorizationRequired(__('None of the granted Facebook Pages has an Instagram professional account linked to it. Link the Instagram account to a Facebook Page, then connect again and choose that Page.'));
        }

        return array_values($profiles);
    }

    public function refresh(string $refreshToken): TokenSet
    {
        throw new ReauthorizationRequired(__('Instagram connections cannot be refreshed. Connect the Instagram account again.'));
    }

    /**
     * Not reached through the publisher ({@see usesSteps()} is always true). A direct call runs one bounded pass;
     * when Instagram is still processing, nothing was published and the call may be repeated.
     */
    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        $deadline = microtime(true) + max(1.0, (float) config('social.publishing.run_budget_seconds', 35));
        $progress = $this->advance($account, $payload, new PublishSession, $deadline);

        return $progress->published
            ?? throw new PublishFailed(__('Instagram is still processing the media. Try again shortly.'), 'still_processing', retryable: true);
    }

    public function usesSteps(PublishPayload $payload): bool
    {
        // Every Instagram post is a container, a wait for it, then media_publish.
        return true;
    }

    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        return $this->publisher()->advance($account, $payload, $session, $deadline);
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        $counts = $this->publisher()->counts($account, $externalPostId);
        $snapshot = new PostMetricsSnapshot(likes: $counts['likes'], comments: $counts['comments']);

        return $snapshot->isEmpty() ? null : $snapshot;
    }

    /** @return list<PostType> */
    public function postTypes(): array
    {
        // Instagram publishes every video as a Reel, so the Video type is published as a Reel too.
        return [PostType::Post, PostType::Photos, PostType::Video, PostType::Reel];
    }

    public function checkPost(PostType $type, array $media): array
    {
        return (new InstagramMediaRules)->check($type, $media);
    }

    public function mediaBadges(MediaInfo $media): array
    {
        return (new InstagramMediaRules)->badges($media);
    }

    public function mediaIssues(array $media, string $text): array
    {
        return (new InstagramMediaRules)->issues($media, $text);
    }

    private function publisher(): InstagramPublisher
    {
        return new InstagramPublisher(new InstagramGraph(
            fn () => $this->client(),
            fn (Closure $request): Response => $this->send($request),
            $this->setting('api_base') ?? 'https://graph.facebook.com',
            $this->version(),
        ));
    }

    private function switchedOn(): bool
    {
        return filter_var(config('social.platforms.instagram.publishing', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = array_values(array_filter((array) config('social.platforms.instagram.scopes', []), static fn (mixed $scope): bool => is_string($scope) && $scope !== ''));

        return $scopes === [] ? self::DEFAULT_SCOPES : array_values(array_unique($scopes));
    }

    private function facebook(string $name): ?string
    {
        $value = config('social.platforms.facebook.'.$name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function limit(string $key, mixed $default): mixed
    {
        return config('social.platforms.instagram.limits.'.$key, $default);
    }

    private function graph(string $path): string
    {
        return rtrim($this->setting('api_base') ?? 'https://graph.facebook.com', '/').'/'.$this->version().'/'.ltrim($path, '/');
    }

    private function version(): string
    {
        return $this->facebook('api_version') ?? 'v23.0';
    }
}
