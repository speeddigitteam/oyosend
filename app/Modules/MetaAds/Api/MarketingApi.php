<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Api;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * The only code that talks to Meta's Graph / Marketing API for this app.
 *
 * Documented calls used: `dialog/oauth`, `oauth/access_token` (code and `fb_exchange_token`), `me`,
 * `me/permissions`, `me/adaccounts`, `{act}/campaigns|adsets|ads|insights`, and `POST {id}` with `status`,
 * `daily_budget` or `lifetime_budget`. Nothing is created or deleted.
 *
 * The access token travels only in the Authorization header (with an `appsecret_proof`), never in a URL, a log
 * line, an exception message or a result. Pagination follows `paging.cursors.after`, never `paging.next`.
 */
final class MarketingApi
{
    public function __construct(private readonly Factory $http) {}

    public function configured(): bool
    {
        return $this->setting('app_id') !== null && $this->setting('app_secret') !== null;
    }

    public function version(): string
    {
        return $this->setting('api_version') ?? 'v23.0';
    }

    /** @return list<string> The permissions the connect dialog asks for. */
    public function scopes(): array
    {
        return config('meta_ads.request_management_scope', true) === true ? ['ads_read', 'ads_management'] : ['ads_read'];
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->setting('app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
        ]);
    }

    /** Exchange an authorisation code for a (short-lived) user token. */
    public function exchangeCode(string $code, string $redirectUri): TokenGrant
    {
        $this->requireConfigured();

        $response = $this->guard(null, fn (): Response => $this->client()->asForm()->post($this->url('oauth/access_token'), [
            'client_id' => $this->setting('app_id'),
            'client_secret' => $this->setting('app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));

        return $this->grant($response);
    }

    /** Exchange a token for a long-lived one (about 60 days). Also used to extend a still-valid long-lived token. */
    public function extend(string $token): TokenGrant
    {
        $this->requireConfigured();

        $response = $this->guard($token, fn (): Response => $this->client()->asForm()->post($this->url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->setting('app_id'),
            'client_secret' => $this->setting('app_secret'),
            'fb_exchange_token' => $token,
        ]));

        return $this->grant($response, $token);
    }

    /** @param array<string, mixed> $query */
    public function get(string $token, string $path, array $query = []): ApiResponse
    {
        $this->requireConfigured();

        $response = $this->guard($token, fn (): Response => $this->client($token)->get($this->url($path), $query + $this->proof($token)));

        return $this->success($response);
    }

    /** @param array<string, scalar> $fields */
    public function post(string $token, string $path, array $fields): ApiResponse
    {
        $this->requireConfigured();

        $response = $this->guard($token, fn (): Response => $this->client($token)->asForm()->post($this->url($path), $fields + $this->proof($token)));

        return $this->success($response);
    }

    /**
     * Read an edge page by page, newest cursor first, within a page budget.
     *
     * @param  array<string, mixed>  $query
     * @param  Closure(list<array<string, mixed>>): void  $handle  receives each page's rows
     * @return array{complete: bool, usage: ApiUsage} `complete` is false when the page budget ended the read
     */
    public function paginate(string $token, string $path, array $query, int $maxPages, Closure $handle, ?Closure $stop = null): array
    {
        $usage = ApiUsage::none();
        $after = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->get($token, $path, $after === null ? $query : $query + ['after' => $after]);
            $usage = $usage->merge($response->usage);
            $handle($response->rows());
            $after = $response->nextCursor();

            if ($after === null) {
                return ['complete' => true, 'usage' => $usage];
            }

            if ($stop !== null && $stop($usage) === true) {
                return ['complete' => false, 'usage' => $usage];
            }
        }

        return ['complete' => false, 'usage' => $usage];
    }

    private function grant(Response $response, ?string $fallbackToken = null): TokenGrant
    {
        $token = $response->json('access_token');
        $seconds = $response->json('expires_in');

        if (! is_string($token) || $token === '') {
            throw new MetaApiException(__('Meta did not return an access token.'), ErrorKind::Rejected, 'no_token');
        }

        $expires = is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;

        return new TokenGrant($token, $expires);
    }

    private function success(Response $response): ApiResponse
    {
        $json = $response->json();

        return new ApiResponse(is_array($json) ? $json : [], ApiUsage::fromResponse($response));
    }

    /**
     * Run a request; a failed one becomes a {@see MetaApiException} with a token-free message.
     *
     * @param  Closure(): Response  $request
     */
    private function guard(?string $token, Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new MetaApiException(__('Meta could not be reached. Try again in a few minutes.'), ErrorKind::Transient, 'network_error');
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->failure($response, $token);
    }

    private function failure(Response $response, ?string $token): MetaApiException
    {
        $code = (int) $response->json('error.code', 0);
        $subcode = (int) $response->json('error.error_subcode', 0);
        $usage = ApiUsage::fromResponse($response);
        $message = $this->clean($response->json('error.message'), __('Meta rejected the request.'), $token);

        if (in_array($code, [102, 190, 459, 464, 467], true) || $response->status() === 401) {
            return new MetaApiException(
                __('Meta no longer accepts this authorisation. It expired or was removed. Authorise again to continue.'),
                ErrorKind::Token,
                'reauthorization_required',
                usage: $usage,
            );
        }

        if (in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80099) || $response->status() === 429) {
            $header = $response->header('Retry-After');
            $retry = is_numeric($header) ? max(1, min(3600, (int) $header)) : ($usage->regainSeconds);

            return new MetaApiException(
                __('Meta is limiting how fast this ad account can be read. It will be tried again later.'),
                ErrorKind::RateLimit,
                'rate_limited',
                $retry,
                $usage,
            );
        }

        if (in_array($code, [3, 10], true) || ($code >= 200 && $code <= 299)) {
            return new MetaApiException(
                __('This authorisation is missing a permission, or its holder has no access to the ad account.'),
                ErrorKind::Permission,
                'permission_missing',
                usage: $usage,
            );
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            return new MetaApiException($message, ErrorKind::Transient, 'meta_server_error', usage: $usage);
        }

        return new MetaApiException($message, ErrorKind::Rejected, 'meta_'.($code > 0 ? $code.($subcode > 0 ? '_'.$subcode : '') : $response->status()), usage: $usage);
    }

    private function clean(mixed $message, string $fallback, ?string $token): string
    {
        if (! is_string($message) || trim($message) === '') {
            return $fallback;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', $message));

        if ($token !== null && $token !== '') {
            $text = str_replace($token, '[removed]', $text);
        }

        $secret = $this->setting('app_secret');

        if ($secret !== null) {
            $text = str_replace($secret, '[removed]', $text);
        }

        return Str::limit($text, 300, '');
    }

    /** @return array<string, string> */
    private function proof(string $token): array
    {
        $secret = $this->setting('app_secret');

        return $secret === null ? [] : ['appsecret_proof' => hash_hmac('sha256', $token, $secret)];
    }

    private function client(?string $token = null): PendingRequest
    {
        $client = $this->http->timeout(30)->connectTimeout(10)->acceptJson()->withUserAgent('Oyosend-MetaAds/1.0');

        return $token === null ? $client : $client->withToken($token);
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    private function requireConfigured(): void
    {
        if (! $this->configured()) {
            throw new MetaApiException(
                __('The Meta app is not configured on this installation. The operator must register a Meta app and set its id and secret.'),
                ErrorKind::NotConfigured,
                'not_configured',
            );
        }
    }

    private function setting(string $name): ?string
    {
        $value = config('meta_ads.'.$name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
