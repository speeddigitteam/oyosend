<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Adapters;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\AuthorizationRequest;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PlatformCapabilities;
use App\Modules\Social\Platforms\PostMetricsSnapshot;
use App\Modules\Social\Platforms\PostTypeAware;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Platforms\ProvidesPostOptions;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\StepwisePublisher;
use App\Modules\Social\Platforms\TikTok\TikTokApi;
use App\Modules\Social\Platforms\TikTok\TikTokErrors;
use App\Modules\Social\Platforms\TikTok\TikTokPostOptions;
use App\Modules\Social\Platforms\TikTok\TikTokUploadUrls;
use App\Modules\Social\Platforms\TikTok\TikTokVideoPublisher;
use App\Modules\Social\Platforms\TokenSet;
use App\Modules\Social\Platforms\ValidatesMedia;
use Illuminate\Http\Client\Response;

/**
 * TikTok creator accounts through Login Kit (OAuth 2.0 v2) and the Content Posting API, Direct Post.
 *
 * Documented calls used: `https://www.tiktok.com/v2/auth/authorize/` (`client_key`, comma-separated scopes),
 * `POST /v2/oauth/token/` (authorization code and refresh grants; access tokens live about 24 hours, refresh
 * tokens about a year), `GET /v2/user/info/?fields=open_id,display_name,avatar_url`, and for publishing
 * `POST /v2/post/publish/creator_info/query/`, `POST /v2/post/publish/video/init/` (`FILE_UPLOAD`), `PUT`
 * of each chunk to the returned `upload_url` and `POST /v2/post/publish/status/fetch/`
 * ({@see TikTokVideoPublisher}).
 *
 * Video only (one video per post). Photo posts need `PULL_FROM_URL` from a domain verified with TikTok and
 * are not implemented. Until TikTok audits the operator's app, everything it posts is private
 * (`SELF_ONLY`) and the creator account itself must be private. No analytics. The client key is stored
 * under `client_id` (ADR 0033). NOT yet exercised against TikTok's live API in this repository.
 *
 * The creator chooses the privacy level, the comment / duet / stitch switches and the commercial content
 * disclosure per post in the composer, from the creator info of that account ({@see ProvidesPostOptions},
 * {@see TikTokPostOptions}); publishing re-checks the privacy level against a fresh creator info.
 */
final class TikTokPlatform extends HttpPlatform implements PostTypeAware, ProvidesPostOptions, StepwisePublisher, ValidatesMedia
{
    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            publish: true, schedule: true, analytics: false, comments: false,
            images: false, video: true, maxText: $this->limit('caption_max', 2200), maxMedia: 1,
            requiresVideo: true,
            maxVideoBytes: $this->limit('video_max_bytes', 0),
            maxVideoSeconds: $this->limit('video_max_seconds', 0),
            minVideoSeconds: $this->limit('video_min_seconds', 0),
        );
    }

    /** TikTok counts the caption in UTF-16 code units. */
    public function textLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        return new AuthorizationRequest(($this->setting('auth_base') ?? 'https://www.tiktok.com/v2/auth/authorize/').'?'.http_build_query([
            'client_key' => $this->setting('client_id'),
            'scope' => implode(',', $this->scopes()),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]));
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        $response = $this->send(fn (): Response => $this->client()->asForm()->post($this->api()->url('oauth/token/'), [
            'client_key' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]));

        $tokens = $this->tokens($response, __('TikTok did not accept the authorization.'));

        if (! in_array('video.publish', $tokens->scopes, true)) {
            throw new ReauthorizationRequired(__('TikTok did not grant permission to post videos. Connect the account again and allow video posting.'));
        }

        $profile = $this->send(fn (): Response => $this->client()->withToken($tokens->accessToken)
            ->get($this->api()->url('user/info/'), ['fields' => 'open_id,display_name,avatar_url']));
        $user = TikTokApi::ok($profile) ? $profile->json('data.user') : null;
        $openId = is_array($user) ? ($user['open_id'] ?? null) : null;
        $openId = is_string($openId) && $openId !== '' ? $openId : $response->json('open_id');

        if (! is_array($user) || ! is_string($openId) || $openId === '') {
            throw new ReauthorizationRequired(__('The TikTok profile could not be read. Check that the app has the user.info.basic scope.'));
        }

        $avatar = $user['avatar_url'] ?? null;

        return [new ProfileConnection(
            externalId: $openId,
            name: $this->clean($user['display_name'] ?? null, 'TikTok account'),
            handle: null,
            avatarUrl: is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
            type: 'profile',
            tokens: $tokens,
        )];
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $response = $this->send(fn (): Response => $this->client()->asForm()->post($this->api()->url('oauth/token/'), [
            'client_key' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]));

        return $this->tokens($response, __('TikTok did not accept the refresh token.'));
    }

    /** Everything TikTok can take is a video, published in steps; anything that reaches here is refused. */
    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        if ($payload->media === []) {
            throw PublishFailed::rejected(__('TikTok publishes only videos. Add a video.'), 'media_missing');
        }

        if (! $payload->media[0]->isVideo) {
            throw PublishFailed::rejected(__('TikTok photo posts are not supported. Only a video can be posted to TikTok.'), 'unsupported_media');
        }

        throw PublishFailed::rejected(__('TikTok publishes exactly one video per post.'), 'unsupported_media');
    }

    public function usesSteps(PublishPayload $payload): bool
    {
        return count($payload->media) === 1 && $payload->media[0]->isVideo;
    }

    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        return (new TikTokVideoPublisher($this->api(), new TikTokUploadUrls, $this->limit('chunk_bytes', 10 * 1024 * 1024)))
            ->advance($account, $payload, $session, $deadline);
    }

    /** The creator info of the account (`POST /v2/post/publish/creator_info/query/`, read-only). */
    public function postOptions(AccountCredentials $account): array
    {
        return TikTokPostOptions::fromCreatorInfo(
            $this->api()->call($account->accessToken, 'post/publish/creator_info/query/', [], read: true),
        );
    }

    public function normalizePostOptions(array $input): array
    {
        return TikTokPostOptions::normalize($input);
    }

    public function postOptionIssues(?array $options, PostType $type, array $media): array
    {
        return TikTokPostOptions::issues($options, $type, $media);
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        return null;
    }

    /** @return list<PostType> */
    public function postTypes(): array
    {
        return [PostType::Post, PostType::Video, PostType::Reel];
    }

    public function checkPost(PostType $type, array $media): array
    {
        if (! in_array($type, $this->postTypes(), true)) {
            return [['blocking' => true, 'message' => __('TikTok does not publish photo posts. Choose a video post.')]];
        }

        if (count($media) > 1) {
            return [['blocking' => true, 'message' => __('TikTok publishes exactly one video per post.')]];
        }

        return [];
    }

    public function mediaBadges(MediaInfo $media): array
    {
        if (! $media->isVideo || ! in_array($media->mime, $this->videoMimes(), true)) {
            return [['code' => 'unsupported', 'tone' => 'muted']];
        }

        $maxBytes = $this->limit('video_max_bytes', 0);
        $maxSeconds = $this->limit('video_max_seconds', 0);

        if ($maxBytes > 0 && $media->sizeBytes > $maxBytes) {
            return [['code' => 'video_too_large', 'tone' => 'danger']];
        }

        if ($maxSeconds > 0 && $media->durationMs !== null && $media->durationMs > $maxSeconds * 1000) {
            return [['code' => 'video_too_long', 'tone' => 'danger']];
        }

        return [['code' => 'video_ok', 'tone' => 'ok']];
    }

    public function mediaIssues(array $media, string $text): array
    {
        $items = [];
        $minSide = $this->limit('video_min_side', 0);
        $maxSide = $this->limit('video_max_side', 0);

        foreach ($media as $item) {
            if (! $item->isVideo) {
                continue;
            }

            if (! in_array($item->mime, $this->videoMimes(), true)) {
                $items[] = ['blocking' => true, 'message' => __('TikTok accepts MP4, MOV or WebM video only.')];
            }

            if ($item->width === null || $item->height === null) {
                continue;
            }

            if ($minSide > 0 && min($item->width, $item->height) < $minSide) {
                $items[] = ['blocking' => true, 'message' => __('TikTok needs a video at least :min pixels on each side.', ['min' => (string) $minSide])];
            }

            if ($maxSide > 0 && max($item->width, $item->height) > $maxSide) {
                $items[] = ['blocking' => true, 'message' => __('TikTok accepts videos of at most :max pixels on each side.', ['max' => (string) $maxSide])];
            }
        }

        // Said every time, because the adapter cannot know whether TikTok has audited the operator's app.
        $items[] = ['blocking' => false, 'message' => __('Until TikTok has audited this app, TikTok posts are private (only the account owner sees them) and the TikTok account must be set to private.')];

        return $items;
    }

    private function api(): TikTokApi
    {
        return new TikTokApi(
            fn () => $this->client(),
            fn (\Closure $request): Response => $this->send($request),
            $this->setting('api_base') ?? 'https://open.tiktokapis.com',
            $this->setting('api_version') ?? 'v2',
        );
    }

    private function tokens(Response $response, string $fallback): TokenSet
    {
        if ($response->status() === 429 || $response->serverError()) {
            throw new PublishFailed(__('TikTok could not be reached. Try again shortly.'), 'platform_error', retryable: true);
        }

        $access = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($access) || $access === '') {
            throw new ReauthorizationRequired(TikTokErrors::clean($response->json('error_description'), $fallback));
        }

        $refresh = $response->json('refresh_token');
        $scope = $response->json('scope');

        return new TokenSet(
            accessToken: $access,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            expiresAt: $this->expiresIn($response->json('expires_in')),
            refreshExpiresAt: $this->expiresIn($response->json('refresh_expires_in')),
            scopes: is_string($scope) ? array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: [])) : $this->scopes(),
        );
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('social.platforms.tiktok.scopes');

        return is_array($scopes) && $scopes !== []
            ? array_values(array_filter($scopes, 'is_string'))
            : ['user.info.basic', 'video.publish'];
    }

    /** @return list<string> */
    private function videoMimes(): array
    {
        $mimes = config('social.platforms.tiktok.limits.video_mimes');

        return is_array($mimes) ? array_values(array_filter($mimes, 'is_string')) : ['video/mp4', 'video/quicktime', 'video/webm'];
    }

    private function limit(string $name, int $default): int
    {
        $value = config('social.platforms.tiktok.limits.'.$name);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
