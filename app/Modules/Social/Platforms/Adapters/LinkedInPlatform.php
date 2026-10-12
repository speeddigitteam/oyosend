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
 * LinkedIn member profile, text posts, through the Posts API (`POST /rest/posts`).
 *
 * Documented calls used: OAuth 2.0 authorization code flow (`/oauth/v2/authorization`,
 * `/oauth/v2/accessToken`), OpenID Connect `userinfo` for the member id, and `rest/posts` with the
 * `LinkedIn-Version` and `X-Restli-Protocol-Version` headers and the `w_member_social` scope. The
 * `commentary` field is "little text format": reserved characters are escaped.
 *
 * Text only: image and video uploads (Images/Videos APIs) and organization pages
 * (Community Management API approval) are not implemented. Needs the "Share on LinkedIn" product
 * enabled on the LinkedIn app. NOT yet exercised against LinkedIn's live API in this repository.
 */
final class LinkedInPlatform extends HttpPlatform
{
    private const array SCOPES = ['openid', 'profile', 'w_member_social'];

    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn';
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            publish: true, schedule: true, analytics: false, comments: false,
            images: false, video: false, maxText: 3000, maxMedia: 0,
        );
    }

    public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
    {
        return new AuthorizationRequest('https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', self::SCOPES),
        ]));
    }

    public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
    {
        $response = $this->send(fn (): Response => $this->client()->asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
        ]));

        $tokens = $this->tokens($response, __('LinkedIn did not accept the authorization.'));

        $profile = $this->send(fn (): Response => $this->client()->withToken($tokens->accessToken)->get('https://api.linkedin.com/v2/userinfo'));
        $id = $profile->successful() ? $profile->json('sub') : null;

        if (! is_string($id) || $id === '') {
            throw new ReauthorizationRequired(__('The LinkedIn profile could not be read. Check that "Sign In with LinkedIn using OpenID Connect" is enabled for the app.'));
        }

        $picture = $profile->json('picture');

        return [new ProfileConnection(
            externalId: $id,
            name: $this->clean($profile->json('name'), 'LinkedIn member'),
            handle: null,
            avatarUrl: is_string($picture) ? $picture : null,
            type: 'profile',
            tokens: $tokens,
        )];
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $response = $this->send(fn (): Response => $this->client()->asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
        ]));

        return $this->tokens($response, __('LinkedIn did not accept the refresh token.'));
    }

    public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
    {
        if ($payload->media !== []) {
            throw PublishFailed::rejected(__('Media posts are not supported for LinkedIn yet.'), 'unsupported_media');
        }

        $text = $payload->text;

        if ($payload->linkUrl !== null && ! str_contains($text, $payload->linkUrl)) {
            $text = trim($text."\n\n".$payload->linkUrl);
        }

        $response = $this->send(fn (): Response => $this->client()
            ->withToken($account->accessToken)
            ->withHeaders([
                'LinkedIn-Version' => $this->setting('api_version') ?? '202510',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->asJson()
            ->post('https://api.linkedin.com/rest/posts', [
                'author' => 'urn:li:person:'.$account->externalId,
                'commentary' => self::escape($text),
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ]));

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $urn = $response->header('x-restli-id');

        if ($urn === '') {
            throw PublishFailed::unknownOutcome(__('LinkedIn accepted the request but returned no post id. Check the profile before trying again.'), 'missing_post_id');
        }

        return new PublishedPost($urn, 'https://www.linkedin.com/feed/update/'.$urn);
    }

    public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
    {
        return null;
    }

    /** Escape the reserved characters of LinkedIn's "little text format"; hashtags stay hashtags. */
    public static function escape(string $text): string
    {
        $escaped = preg_replace('/([\\\\|{}@\[\]()<>*_~])/u', '\\\\$1', $text) ?? $text;

        return preg_replace('/#(?![\p{L}\p{M}\p{N}_])/u', '\\\\#', $escaped) ?? $escaped;
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
            refreshExpiresAt: $this->expiresIn($response->json('refresh_token_expires_in')),
            scopes: is_string($scope) ? array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: [])) : self::SCOPES,
        );
    }

    private function failure(Response $response): PublishFailed
    {
        $message = $this->clean($response->json('message'), __('LinkedIn rejected the request.'));

        return match (true) {
            $response->status() === 401 => PublishFailed::reauthorization($message),
            $response->status() === 403 => PublishFailed::reauthorization(__('LinkedIn denied the request: the app or member lacks the required permission.')),
            $response->status() === 429 => PublishFailed::rateLimited($message, $this->retryAfter($response)),
            $response->serverError() => PublishFailed::unknownOutcome($message, 'platform_error'),
            $response->status() === 422 => PublishFailed::rejected($message, 'duplicate_or_invalid'),
            default => PublishFailed::rejected($message, 'linkedin_'.$response->status()),
        };
    }
}
