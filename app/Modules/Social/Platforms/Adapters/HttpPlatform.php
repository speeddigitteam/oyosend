<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Adapters;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\SocialPlatform;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Shared plumbing of the HTTP adapters: credentials from `config/social.php`, one HTTP client
 * profile, token-free error text and the connect-vs-unknown outcome decision.
 */
abstract class HttpPlatform implements SocialPlatform
{
    public function __construct(protected readonly Factory $http) {}

    public function availability(): PlatformAvailability
    {
        if ($this->setting('client_id') === null || $this->setting('client_secret') === null) {
            return PlatformAvailability::unavailable(
                PlatformAvailability::NOT_CONFIGURED,
                __(':platform app credentials are not configured. The operator must register an app (including its app review) and set its client id and secret.', ['platform' => $this->label()]),
            );
        }

        return PlatformAvailability::available();
    }

    public function textLength(string $text): int
    {
        return mb_strlen($text);
    }

    protected function setting(string $name): ?string
    {
        $value = config('social.platforms.'.$this->key().'.'.$name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function client(): PendingRequest
    {
        return $this->http->timeout(20)->connectTimeout(10)->acceptJson()->withUserAgent('Oyosend-Social/1.0');
    }

    /**
     * Run a request. A failure to even connect is safe to retry; any other transport error
     * (a timeout after the request was sent) may have been processed by the platform.
     *
     * @param  Closure(): Response  $request
     */
    protected function send(Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            $message = $exception->getMessage();
            $neverSent = str_contains($message, 'Could not resolve host')
                || str_contains($message, 'Failed to connect')
                || str_contains($message, 'Connection refused')
                || str_contains($message, 'cURL error 6')
                || str_contains($message, 'cURL error 7');

            if ($neverSent) {
                throw new PublishFailed(__('The platform could not be reached.'), 'network_unreachable', retryable: true);
            }

            throw PublishFailed::unknownOutcome(
                __('The platform did not confirm the request. Check the account before trying again.'),
                'network_unconfirmed',
            );
        }
    }

    protected function expiresIn(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }

    protected function clean(mixed $message, string $fallback): string
    {
        if (! is_string($message) || trim($message) === '') {
            return $fallback;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message) ?? $fallback), 300, '');
    }

    protected function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, min(3600, (int) $header)) : null;
    }
}
