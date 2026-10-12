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
use Illuminate\Support\Str;

/**
 * X (Twitter) text posts through API v2 with OAuth 2.0 authorization code flow and PKCE.
 *
 * Documented calls used: `https://x.com/i/oauth2/authorize`, `https://api.x.com/2/oauth2/token`
 * (authorization code and refresh grants, scopes `tweet.read tweet.write users.read offline.access`),
 * `GET /2/users/me`, `POST /2/tweets` and `GET /2/tweets/:id?tweet.fields=public_metrics`.
 *
 * Text only (media upload is a separate endpoint, not implemented). Requires an X developer
 * project whose access tier allows posting. NOT yet exercised against X's live API in this repository.
 */
final class XPlatform extends HttpPlatform
{
    private const array SCOPES = ['tweet.read', 'tweet.write', 'users.read', 'offline.access'];

    /** Characters X counts for any link. */
    private const int LINK_LENGTH = 23;

    public function key(): string
    {
        return 'x';
    }

    public function label(): string
    {
        return 'X';
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            publish: true, schedule: true, analytics: true, comments: false,
            images: false, video: false, maxText: 280, maxMedia: 0,
        );
    }

    /** Length as X counts it: every link counts as 23 characters. */
    public function textLength(string $text): int
    {
        $stripped = preg_replace('~https?://\S+~iu', str_repeat('x', self::LINK_LENGTH), $text) ?? $text;

        return mb_strlen($stripped);
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return new AuthorizationRequest('https://x.com/i/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
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
            'client_id' => $this->setting('client_id'),
        ]));

        $tokens = $this->tokens($response, __('X did not accept the authorization.'));

        $me = $this->send(fn (): Response => $this->client()->withToken($tokens->accessToken)->get('https://api.x.com/2/users/me', [
            'user.fields' => 'profile_image_url',
        ]));
        $id = $me->successful() ? $me->json('data.id') : null;

        if (! is_string($id) || $id === '') {
            throw new ReauthorizationRequired(__('The X account could not be read.'));
        }

        $username = $me->json('data.username');
        $avatar = $me->json('data.profile_image_url');

        return [new ProfileConnection(
            externalId: $id,
            name: $this->clean($me->json('data.name'), 'X account'),
            handle: is_string($username) ? '@'.$username : null,
            avatarUrl: is_string($avatar) ? $avatar : null,
            type: 'profile',
            tokens: $tokens,
        )];
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $response = $this->send(fn (): Response => $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->setting('client_id'),
        ]));

        return $this->tokens($response, __('X did not accept the refresh token.'));
    }

    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        if ($payload->media !== []) {
            throw PublishFailed::rejected(__('Media posts are not supported for X yet.'), 'unsupported_media');
        }

        $text = $payload->text;

        if ($payload->linkUrl !== null && ! str_contains($text, $payload->linkUrl)) {
            $text = trim($text."\n".$payload->linkUrl);
        }

        $response = $this->send(fn (): Response => $this->client()->withToken($account->accessToken)
            ->asJson()->post('https://api.x.com/2/tweets', ['text' => $text]));

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $id = $response->json('data.id');

        if (! is_string($id) || $id === '') {
            throw PublishFailed::unknownOutcome(__('X accepted the request but returned no post id. Check the account before trying again.'), 'missing_post_id');
        }

        return new PublishedPost($id, 'https://x.com/i/web/status/'.$id);
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        $response = $this->send(fn (): Response => $this->client()->withToken($account->accessToken)
            ->get('https://api.x.com/2/tweets/'.rawurlencode($externalPostId), ['tweet.fields' => 'public_metrics']));

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $int = static fn (mixed $value): ?int => is_int($value) ? $value : null;
        $snapshot = new PostMetricsSnapshot(
            impressions: $int($response->json('data.public_metrics.impression_count')),
            likes: $int($response->json('data.public_metrics.like_count')),
            comments: $int($response->json('data.public_metrics.reply_count')),
            shares: $int($response->json('data.public_metrics.retweet_count')),
        );

        return $snapshot->isEmpty() ? null : $snapshot;
    }

    /** @param array<string, string|null> $form */
    private function token(array $form): Response
    {
        return $this->client()
            ->withBasicAuth((string) $this->setting('client_id'), (string) $this->setting('client_secret'))
            ->asForm()
            ->post('https://api.x.com/2/oauth2/token', $form);
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
            scopes: is_string($scope) ? array_values(array_filter(preg_split('/\s+/', $scope) ?: [])) : self::SCOPES,
        );
    }

    private function failure(Response $response): PublishFailed
    {
        $message = $this->clean($response->json('detail') ?? $response->json('title'), __('X rejected the request.'));

        if ($response->status() === 429) {
            $reset = $response->header('x-rate-limit-reset');
            $seconds = is_numeric($reset) ? max(1, min(3600, (int) $reset - time())) : $this->retryAfter($response);

            return PublishFailed::rateLimited($message, $seconds);
        }

        return match (true) {
            $response->status() === 401 => PublishFailed::reauthorization($message),
            $response->serverError() => PublishFailed::unknownOutcome($message, 'platform_error'),
            $response->status() === 403 => PublishFailed::rejected($message, 'forbidden'),
            default => PublishFailed::rejected($message, 'x_'.$response->status()),
        };
    }
}
