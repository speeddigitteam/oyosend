<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Adapters;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\AuthorizationRequest;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\Pinterest\PinterestApi;
use App\Modules\Social\Platforms\Pinterest\PinterestMediaRules;
use App\Modules\Social\Platforms\Pinterest\PinterestPins;
use App\Modules\Social\Platforms\Pinterest\PinterestVideoPublisher;
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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Pinterest boards through the Pinterest API v5 (ADR 0033).
 *
 * Documented calls used: OAuth 2.0 authorization code flow (`https://www.pinterest.com/oauth/`, then
 * `POST /v5/oauth/token` with HTTP Basic client authentication for `authorization_code` and `refresh_token`,
 * asking for the continuous refresh token), `GET /v5/user_account`, `GET /v5/boards` (bookmark pagination),
 * `POST /v5/pins` with `media_source` `image_base64`, `multiple_image_base64` (carousel, 2 to 5 images) or
 * `video_id`, the video upload (`POST /v5/media`, the multipart upload to the returned `upload_url`,
 * `GET /v5/media/{id}`) and `GET /v5/pins/{id}/analytics`.
 *
 * A connected account is one board: authorising lists every board as a profile, all sharing the member's
 * token. A Pin needs media (`requiresMedia`). Images travel as base64, so no library file is made public.
 * Creating Pins on `api.pinterest.com` needs Pinterest Standard access (Trial access only reaches the
 * sandbox). NOT yet exercised against Pinterest's live API in this repository.
 */
final class PinterestPlatform extends HttpPlatform implements StepwisePublisher, ValidatesMedia
{
    /** Boards listed per page and the most pages read (Pinterest allows up to 250 per page). */
    private const int BOARDS_PAGE_SIZE = 100;

    private const int BOARDS_MAX_PAGES = 10;

    public function key(): string
    {
        return 'pinterest';
    }

    public function label(): string
    {
        return 'Pinterest';
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            publish: true, schedule: true, analytics: true, comments: false,
            images: true, video: true,
            maxText: max(1, (int) PinterestMediaRules::limit('description_max', 500)),
            maxMedia: PinterestMediaRules::carouselMax(),
            requiresMedia: true,
            maxVideoBytes: max(0, (int) PinterestMediaRules::limit('video_max_bytes', 0)),
            maxVideoSeconds: max(0, (int) PinterestMediaRules::limit('video_max_seconds', 0)),
            minVideoSeconds: max(0, (int) PinterestMediaRules::limit('video_min_seconds', 0)),
        );
    }

    public function mediaIssues(array $media, string $text): array
    {
        return (new PinterestMediaRules)->check($media, $text);
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        return new AuthorizationRequest(($this->setting('auth_base') ?? 'https://www.pinterest.com/oauth/').'?'.http_build_query([
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'continuous_refresh' => 'true',
        ], __('Pinterest did not accept the authorization.'));

        $user = $this->send(fn (): Response => $this->client()->withToken($tokens->accessToken)->get($this->api()->url('user_account')));
        $username = $user->successful() ? $user->json('username') : null;

        if (! is_string($username) || trim($username) === '') {
            throw new ReauthorizationRequired(__('The Pinterest account could not be read. Check that the user_accounts:read scope was granted.'));
        }

        $username = $this->clean($username, 'Pinterest');
        $avatar = $user->json('profile_image');
        $profiles = [];

        foreach ($this->boards($tokens->accessToken) as $board) {
            $profiles[] = new ProfileConnection(
                externalId: $board['id'],
                name: $username.' · '.$board['name'],
                handle: null,
                avatarUrl: is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
                type: 'board',
                tokens: $tokens,
            );
        }

        if ($profiles === []) {
            throw new ReauthorizationRequired(__('This Pinterest account has no boards. Create a board on Pinterest first, then connect again.'));
        }

        return $profiles;
    }

    public function refresh(string $refreshToken): TokenSet
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'continuous_refresh' => 'true',
        ], __('Pinterest did not accept the refresh token.'));
    }

    public function usesSteps(PublishPayload $payload): bool
    {
        foreach ($payload->media as $file) {
            if ($file->isVideo) {
                return true;
            }
        }

        return false;
    }

    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        return (new PinterestVideoPublisher($this->api()))->advance($account, $payload, $session, $deadline);
    }

    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        if ($payload->media === []) {
            throw PublishFailed::rejected(__('This platform does not publish text on its own. Add a photo or a video.'), 'media_missing');
        }

        if ($this->usesSteps($payload)) {
            throw PublishFailed::rejected(__('A Pinterest video Pin is published in steps.'), 'unsupported_media');
        }

        $images = array_values(array_filter($payload->media, static fn (MediaFile $file): bool => ! $file->isVideo));

        if (count($images) > PinterestMediaRules::carouselMax()) {
            throw PublishFailed::rejected(__('This platform accepts at most :max media items.', ['max' => (string) PinterestMediaRules::carouselMax()]), 'too_many_media');
        }

        return (new PinterestPins($this->api()))->create($account, $payload, PinterestPins::imageSource($images), $images[0]);
    }

    /**
     * Impressions, saves (as shares), Pin and outbound clicks over the last 90 days (the furthest Pinterest
     * reports back; posts are read for `social.metrics.window_days`, 30 by default, so this covers their
     * whole life), plus lifetime comments and reactions (as likes) when Pinterest returns them.
     */
    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        $today = CarbonImmutable::now('UTC');
        $response = $this->send(fn (): Response => $this->client()->withToken($account->accessToken)
            ->get($this->api()->url('pins/'.rawurlencode($externalPostId).'/analytics'), [
                'start_date' => $today->subDays(89)->toDateString(),
                'end_date' => $today->toDateString(),
                'metric_types' => 'IMPRESSION,SAVE,PIN_CLICK,OUTBOUND_CLICK,TOTAL_COMMENTS,TOTAL_REACTIONS',
                'app_types' => 'ALL',
            ]));

        if (! $response->successful()) {
            throw PinterestApi::failure($response, __('Pinterest no longer has this Pin.'));
        }

        $json = $response->json();
        $section = is_array($json) ? ($json['all'] ?? $json['ALL'] ?? (is_array(reset($json)) ? reset($json) : null)) : null;

        if (! is_array($section)) {
            return null;
        }

        $summary = is_array($section['summary_metrics'] ?? null) ? $section['summary_metrics'] : [];
        $lifetime = is_array($section['lifetime_metrics'] ?? null) ? $section['lifetime_metrics'] : [];
        $int = static fn (mixed $value): ?int => is_int($value) || (is_float($value) && is_finite($value)) ? max(0, (int) round($value)) : null;
        $pinClicks = $int($summary['PIN_CLICK'] ?? null);
        $outbound = $int($summary['OUTBOUND_CLICK'] ?? null);

        $snapshot = new PostMetricsSnapshot(
            impressions: $int($summary['IMPRESSION'] ?? null),
            likes: $int($lifetime['TOTAL_REACTIONS'] ?? $summary['TOTAL_REACTIONS'] ?? null),
            comments: $int($lifetime['TOTAL_COMMENTS'] ?? $summary['TOTAL_COMMENTS'] ?? null),
            shares: $int($summary['SAVE'] ?? null),
            clicks: $pinClicks === null && $outbound === null ? null : (int) $pinClicks + (int) $outbound,
        );

        return $snapshot->isEmpty() ? null : $snapshot;
    }

    /**
     * Every board the token can pin to, following Pinterest's bookmark, up to a sane cap.
     *
     * @return list<array{id: string, name: string}>
     */
    private function boards(string $accessToken): array
    {
        $boards = [];
        $bookmark = null;
        $pages = 0;

        do {
            $query = ['page_size' => self::BOARDS_PAGE_SIZE];

            if ($bookmark !== null) {
                $query['bookmark'] = $bookmark;
            }

            $response = $this->send(fn (): Response => $this->client()->withToken($accessToken)->get($this->api()->url('boards'), $query));

            if (! $response->successful()) {
                throw new ReauthorizationRequired(__('The Pinterest boards could not be listed. Check that the boards:read scope was granted.'));
            }

            foreach ((array) $response->json('items', []) as $board) {
                $id = is_array($board) ? ($board['id'] ?? null) : null;

                if ((is_string($id) && ctype_digit($id)) || is_int($id)) {
                    $boards[(string) $id] = ['id' => (string) $id, 'name' => $this->clean($board['name'] ?? null, 'Board')];
                }
            }

            $next = $response->json('bookmark');
            $bookmark = is_string($next) && $next !== '' && $next !== $bookmark ? $next : null;
            $pages++;
        } while ($bookmark !== null && $pages < self::BOARDS_MAX_PAGES);

        return array_values($boards);
    }

    /**
     * `POST /v5/oauth/token`, client authenticated with HTTP Basic. A refused grant needs a new authorisation;
     * a throttle or a Pinterest outage does not, so it is reported as a failure to retry later.
     *
     * @param  array<string, string>  $form
     */
    private function token(array $form, string $fallback): TokenSet
    {
        $response = $this->send(fn (): Response => $this->client()
            ->withBasicAuth((string) $this->setting('client_id'), (string) $this->setting('client_secret'))
            ->asForm()
            ->post($this->api()->url('oauth/token'), $form));

        if ($response->status() === 429 || $response->serverError()) {
            throw new PublishFailed(__('Pinterest could not be reached. Try again later.'), 'platform_error', retryable: true);
        }

        $access = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($access) || $access === '') {
            throw new ReauthorizationRequired($this->clean($response->json('message') ?? $response->json('error_description'), $fallback));
        }

        $refresh = $response->json('refresh_token');
        $scope = $response->json('scope');

        return new TokenSet(
            accessToken: $access,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            expiresAt: $this->expiresIn($response->json('expires_in')),
            refreshExpiresAt: $this->expiresIn($response->json('refresh_token_expires_in')),
            scopes: is_string($scope) && trim($scope) !== ''
                ? array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: []))
                : $this->scopes(),
        );
    }

    private function api(): PinterestApi
    {
        return new PinterestApi(
            fn () => $this->client(),
            fn (\Closure $request): Response => $this->send($request),
            $this->setting('api_base') ?? 'https://api.pinterest.com',
            $this->setting('api_version') ?? 'v5',
        );
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('social.platforms.pinterest.scopes');

        return is_array($scopes) && $scopes !== []
            ? array_values(array_map('strval', $scopes))
            : ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read'];
    }
}
