<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Adapters;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\AuthorizationRequest;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\PlatformCapabilities;
use App\Modules\Social\Platforms\PostMetricsSnapshot;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\TokenSet;
use Illuminate\Http\Client\Response;

/**
 * Facebook Pages through the Graph API (Facebook Login for Business / Pages API).
 *
 * Documented calls used: `dialog/oauth`, `oauth/access_token` (code and `fb_exchange_token`),
 * `me/accounts`, `{page-id}/feed`, `{page-id}/photos` and a post's `likes` / `comments` / `shares`
 * fields. Page access tokens derived from a long-lived user token have no expiry date, so no
 * refresh is possible; a rejected token sends the account to "needs reauthorization".
 *
 * Needs a Meta app with `pages_show_list`, `pages_manage_posts` and `pages_read_engagement`
 * approved through app review. NOT yet exercised against Meta's live API in this repository.
 */
final class FacebookPagesPlatform extends HttpPlatform
{
    private const array SCOPES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement'];

    private const array RATE_LIMIT_CODES = [4, 17, 32, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    private const array REAUTH_CODES = [10, 102, 190, 459, 464, 467];

    public function key(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook Pages';
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            publish: true, schedule: true, analytics: true, comments: false,
            images: true, video: false, maxText: 63206, maxMedia: 1,
        );
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        return new AuthorizationRequest('https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
        ]));
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        $short = $this->send(fn (): Response => $this->client()->asForm()->post($this->graph('oauth/access_token'), [
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));

        $userToken = $short->successful() ? $short->json('access_token') : null;

        if (! is_string($userToken) || $userToken === '') {
            throw new ReauthorizationRequired($this->clean($short->json('error.message'), __('Facebook did not accept the authorization.')));
        }

        // Prefer the long-lived user token so the page tokens derived from it do not expire.
        $long = $this->send(fn (): Response => $this->client()->asForm()->post($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'fb_exchange_token' => $userToken,
        ]));
        $longToken = $long->successful() ? $long->json('access_token') : null;
        $userToken = is_string($longToken) && $longToken !== '' ? $longToken : $userToken;

        $pages = $this->send(fn (): Response => $this->client()->withToken($userToken)->get($this->graph('me/accounts'), [
            'fields' => 'id,name,access_token,picture{url}',
            'limit' => 100,
        ]));

        if (! $pages->successful()) {
            throw new ReauthorizationRequired($this->clean($pages->json('error.message'), __('The Facebook Pages could not be listed.')));
        }

        $profiles = [];

        foreach ((array) $pages->json('data', []) as $page) {
            if (! is_array($page) || ! is_string($page['id'] ?? null) || ! is_string($page['access_token'] ?? null)) {
                continue;
            }

            $picture = $page['picture']['data']['url'] ?? null;
            $profiles[] = new ProfileConnection(
                externalId: $page['id'],
                name: $this->clean($page['name'] ?? null, 'Facebook Page'),
                handle: null,
                avatarUrl: is_string($picture) ? $picture : null,
                type: 'page',
                tokens: new TokenSet($page['access_token'], scopes: self::SCOPES),
            );
        }

        if ($profiles === []) {
            throw new ReauthorizationRequired(__('No Facebook Page was granted. Choose at least one Page you manage.'));
        }

        return $profiles;
    }

    public function refresh(string $refreshToken): TokenSet
    {
        throw new ReauthorizationRequired(__('Facebook Page tokens cannot be refreshed. Connect the Page again.'));
    }

    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        $image = $payload->media[0] ?? null;

        if ($image !== null && $image->isVideo) {
            throw PublishFailed::rejected(__('Video is not supported for Facebook Pages yet.'), 'unsupported_media');
        }

        $response = $this->send(function () use ($account, $payload, $image): Response {
            $request = $this->client()->withToken($account->accessToken);

            if ($image !== null) {
                return $request->attach('source', $image->contents(), $image->name)
                    ->post($this->graph($account->externalId.'/photos'), [
                        'caption' => $payload->text,
                        'published' => 'true',
                    ]);
            }

            $fields = ['message' => $payload->text];

            if ($payload->linkUrl !== null) {
                $fields['link'] = $payload->linkUrl;
            }

            return $request->asForm()->post($this->graph($account->externalId.'/feed'), $fields);
        });

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $id = $response->json('post_id') ?? $response->json('id');

        if (! is_string($id) || $id === '') {
            throw PublishFailed::unknownOutcome(__('Facebook accepted the request but returned no post id. Check the Page before trying again.'), 'missing_post_id');
        }

        return new PublishedPost($id, 'https://www.facebook.com/'.$id);
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        $response = $this->send(fn (): Response => $this->client()->withToken($account->accessToken)->get($this->graph($externalPostId), [
            'fields' => 'likes.summary(true).limit(0),comments.summary(true).limit(0),shares',
        ]));

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $int = static fn (mixed $value): ?int => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
        $snapshot = new PostMetricsSnapshot(
            likes: $int($response->json('likes.summary.total_count')),
            comments: $int($response->json('comments.summary.total_count')),
            shares: $int($response->json('shares.count')),
        );

        return $snapshot->isEmpty() ? null : $snapshot;
    }

    private function failure(Response $response): PublishFailed
    {
        $code = (int) $response->json('error.code', 0);
        $message = $this->clean($response->json('error.message'), __('Facebook rejected the request.'));

        if (in_array($code, self::REAUTH_CODES, true) || ($code >= 200 && $code <= 299) || $response->status() === 401) {
            return PublishFailed::reauthorization($message);
        }

        if (in_array($code, self::RATE_LIMIT_CODES, true) || $response->status() === 429) {
            return PublishFailed::rateLimited($message, $this->retryAfter($response));
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            return PublishFailed::unknownOutcome($message, 'platform_error');
        }

        return PublishFailed::rejected($message, 'facebook_'.($code > 0 ? $code : $response->status()));
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    private function version(): string
    {
        return $this->setting('api_version') ?? 'v23.0';
    }
}
