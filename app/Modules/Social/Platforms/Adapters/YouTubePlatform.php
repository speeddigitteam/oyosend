<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Adapters;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\AuthorizationRequest;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PlatformCapabilities;
use App\Modules\Social\Platforms\PostMetricsSnapshot;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\StepwisePublisher;
use App\Modules\Social\Platforms\TokenSet;
use App\Modules\Social\Platforms\ValidatesMedia;
use App\Modules\Social\Platforms\YouTube\YouTubeErrors;
use App\Modules\Social\Platforms\YouTube\YouTubeResumableUpload;
use App\Modules\Social\Platforms\YouTube\YouTubeVideoText;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * YouTube channels, video uploads, through the YouTube Data API v3 and Google OAuth 2.0 (web client).
 *
 * Documented calls used: Google's authorization endpoint (`access_type=offline`, `prompt=consent`, PKCE S256)
 * and token endpoint (authorization code and refresh grants), `GET channels?part=snippet&mine=true`, the
 * resumable `videos.insert` upload (`POST upload/youtube/v3/videos?uploadType=resumable&part=snippet,status`,
 * then `PUT` chunks to the session URI; {@see YouTubeResumableUpload}) and `GET videos?part=statistics`.
 *
 * Video only: the title is the first line of the text and the description the whole text
 * ({@see YouTubeVideoText}); the post's internal title is never published. Needs a Google Cloud project with the
 * YouTube Data API v3 enabled and an OAuth web client. Until Google audits the project, uploads are locked
 * to private whatever privacy is asked for; each upload costs 1600 of the default 10,000 daily quota units.
 * NOT yet exercised against Google's live API in this repository.
 */
final class YouTubePlatform extends HttpPlatform implements StepwisePublisher, ValidatesMedia
{
    private const array DEFAULT_SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    private const array PRIVACY = ['public', 'unlisted', 'private'];

    public function __construct(Factory $http, private readonly Encrypter $encrypter)
    {
        parent::__construct($http);
    }

    public function key(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            publish: true, schedule: true, analytics: true, comments: false,
            images: false, video: true, maxText: $this->limit('description_max', 5000), maxMedia: 1,
            requiresVideo: true,
            maxVideoBytes: $this->limit('video_max_bytes', 0),
            maxVideoSeconds: $this->limit('video_max_seconds', 0),
        );
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return new AuthorizationRequest($this->base('auth_base', 'https://accounts.google.com/o/oauth2/v2/auth').'?'.http_build_query([
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986), $verifier);
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        if ($codeVerifier === null) {
            throw new ReauthorizationRequired(__('The authorization session expired. Start again.'));
        }

        $response = $this->send(fn (): Response => $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]));

        $tokens = $this->tokens($response, __('Google did not accept the authorization.'));

        $channels = $this->send(fn (): Response => $this->client()->withToken($tokens->accessToken)
            ->get($this->api('channels'), ['part' => 'snippet', 'mine' => 'true']));

        if (! $channels->successful()) {
            $failure = YouTubeErrors::failure($channels);

            throw $failure->reauthorize ? new ReauthorizationRequired($failure->getMessage()) : $failure;
        }

        $profiles = [];

        foreach ((array) $channels->json('items', []) as $channel) {
            if (! is_array($channel) || ! is_string($channel['id'] ?? null) || $channel['id'] === '') {
                continue;
            }

            $snippet = is_array($channel['snippet'] ?? null) ? $channel['snippet'] : [];
            $avatar = $snippet['thumbnails']['default']['url'] ?? $snippet['thumbnails']['medium']['url'] ?? null;
            $handle = $snippet['customUrl'] ?? null;

            $profiles[] = new ProfileConnection(
                externalId: $channel['id'],
                name: $this->clean($snippet['title'] ?? null, 'YouTube channel'),
                handle: is_string($handle) && $handle !== '' ? $handle : null,
                avatarUrl: is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
                type: 'channel',
                tokens: $tokens,
            );
        }

        if ($profiles === []) {
            throw new ReauthorizationRequired(__('This Google account has no YouTube channel. Create the channel on YouTube, then connect it again.'));
        }

        return $profiles;
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $response = $this->send(fn (): Response => $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]));

        if ($response->serverError() || $response->status() === 429) {
            throw new PublishFailed(__('Google could not renew the access token right now.'), 'platform_error', retryable: true);
        }

        $tokens = $this->tokens($response, __('Google did not accept the refresh token.'));

        // Google usually omits the refresh token on refresh: the one we hold stays valid.
        return $tokens->refreshToken !== null ? $tokens : new TokenSet(
            accessToken: $tokens->accessToken,
            refreshToken: $refreshToken,
            expiresAt: $tokens->expiresAt,
            refreshExpiresAt: $tokens->refreshExpiresAt,
            scopes: $tokens->scopes,
        );
    }

    /** Only a post without media gets here ({@see usesSteps()}), and YouTube publishes video only. */
    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        throw PublishFailed::rejected(__('YouTube publishes video only. Add a video.'), 'media_missing');
    }

    public function usesSteps(PublishPayload $payload): bool
    {
        return $payload->media !== [];
    }

    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        // Never upload twice: once YouTube answered with the video, that answer is the result.
        if ($session->externalId !== null) {
            return PublishProgress::published(new PublishedPost(
                $session->externalId, 'https://www.youtube.com/watch?v='.rawurlencode($session->externalId),
            ));
        }

        $file = $this->video($payload->media);
        $text = YouTubeVideoText::from($payload->text, $this->limit('title_max', 100), $payload->linkUrl, $this->limit('description_max', 5000));

        if (! $text->hasTitle()) {
            throw PublishFailed::rejected(__('YouTube needs text: its first line becomes the video title.'), 'youtube_title_missing');
        }

        $upload = new YouTubeResumableUpload(
            fn () => $this->client(),
            fn (\Closure $request): Response => $this->send($request),
            $this->encrypter,
            $this->base('upload_base', 'https://www.googleapis.com/upload/youtube/v3'),
            $this->limit('chunk_bytes', 8 * 1024 * 1024),
            $this->privacy(),
        );

        return $upload->advance($account, $file, $text, $session, $deadline);
    }

    public function mediaIssues(array $media, string $text): array
    {
        $issues = [];
        $text = YouTubeVideoText::from($text, $this->limit('title_max', 100));
        $descriptionMax = $this->limit('description_max', 5000);

        if (! $text->hasTitle()) {
            $issues[] = ['blocking' => true, 'message' => __('YouTube needs text: its first line becomes the video title.')];
        }

        if ($descriptionMax > 0 && $text->descriptionBytes > $descriptionMax) {
            $issues[] = ['blocking' => true, 'message' => __('The text is too long for a YouTube description (at most :max bytes; letters outside the Latin alphabet take two or three bytes each).', ['max' => $descriptionMax])];
        }

        if ($text->titleShortened) {
            $issues[] = ['blocking' => false, 'message' => __('The first line is longer than :max characters; the YouTube title will be shortened.', ['max' => $this->limit('title_max', 100)])];
        }

        if ($text->removedBrackets) {
            $issues[] = ['blocking' => false, 'message' => __('YouTube does not accept the characters < and >; they will be removed.')];
        }

        // The adapter cannot know whether Google has audited the operator's project, so it says so every time.
        if ($this->privacy() !== 'private') {
            $issues[] = ['blocking' => false, 'message' => __('Until Google audits the operator\'s project, YouTube keeps uploaded videos private.')];
        }

        $mimes = config('social.platforms.youtube.limits.video_mimes');

        foreach ($media as $item) {
            if ($item->isVideo && is_array($mimes) && $mimes !== [] && ! in_array($item->mime, $mimes, true)) {
                $issues[] = ['blocking' => true, 'message' => __('YouTube does not accept this video file type.')];
            }
        }

        return $issues;
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        $response = $this->send(fn (): Response => $this->client()->withToken($account->accessToken)
            ->get($this->api('videos'), ['part' => 'statistics', 'id' => $externalPostId]));

        if (! $response->successful()) {
            throw YouTubeErrors::failure($response);
        }

        // Counts arrive as decimal strings. View counts have no place in the shared snapshot and are not shown.
        $int = static fn (mixed $value): ?int => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
        $snapshot = new PostMetricsSnapshot(
            likes: $int($response->json('items.0.statistics.likeCount')),
            comments: $int($response->json('items.0.statistics.commentCount')),
        );

        return $snapshot->isEmpty() ? null : $snapshot;
    }

    /**
     * @param  list<MediaFile>  $media
     *
     * @throws PublishFailed
     */
    private function video(array $media): MediaFile
    {
        if (count($media) !== 1 || ! $media[0]->isVideo) {
            throw PublishFailed::rejected(__('YouTube publishes exactly one video per post, without photos.'), 'unsupported_media');
        }

        return $media[0];
    }

    /** @param array<string, string> $form */
    private function token(array $form): Response
    {
        return $this->client()->asForm()->post($this->base('token_url', 'https://oauth2.googleapis.com/token'), [
            ...$form,
            'client_id' => (string) $this->setting('client_id'),
            'client_secret' => (string) $this->setting('client_secret'),
        ]);
    }

    private function tokens(Response $response, string $fallback): TokenSet
    {
        $access = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($access) || $access === '') {
            throw new ReauthorizationRequired($this->clean($response->json('error_description'), $fallback));
        }

        $refresh = $response->json('refresh_token');
        $scope = $response->json('scope');

        return new TokenSet(
            accessToken: $access,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            expiresAt: $this->expiresIn($response->json('expires_in')),
            scopes: is_string($scope) ? array_values(array_filter(preg_split('/\s+/', $scope) ?: [])) : $this->scopes(),
        );
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('social.platforms.youtube.scopes');

        $scopes = is_array($scopes) ? array_values(array_filter($scopes, fn (mixed $scope): bool => is_string($scope) && $scope !== '')) : [];

        return $scopes === [] ? self::DEFAULT_SCOPES : $scopes;
    }

    private function privacy(): string
    {
        $privacy = config('social.platforms.youtube.limits.privacy', 'public');

        return is_string($privacy) && in_array($privacy, self::PRIVACY, true) ? $privacy : 'private';
    }

    private function api(string $path): string
    {
        return $this->base('api_base', 'https://www.googleapis.com/youtube/v3').'/'.ltrim($path, '/');
    }

    private function base(string $name, string $default): string
    {
        return rtrim($this->setting($name) ?? $default, '/');
    }

    private function limit(string $name, int $default): int
    {
        $value = config('social.platforms.youtube.limits.'.$name, $default);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
