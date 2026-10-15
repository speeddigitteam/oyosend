<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Pinterest;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * The HTTP helpers the Pinterest adapter and its video publisher share. Requests go through the adapter's own
 * client profile and `send()`, so a refused connection stays retryable and a lost answer stays an unknown
 * outcome. The token travels only in the Authorization header and never in a message.
 */
final class PinterestApi
{
    /**
     * @param  Closure(): PendingRequest  $client
     * @param  Closure(Closure(): Response): Response  $send
     */
    public function __construct(
        private readonly Closure $client,
        private readonly Closure $send,
        private readonly string $base,
        private readonly string $version,
    ) {}

    public function url(string $path): string
    {
        return rtrim($this->base, '/').'/'.trim($this->version, '/').'/'.ltrim($path, '/');
    }

    public function request(): PendingRequest
    {
        return ($this->client)();
    }

    /** @param Closure(): Response $request */
    public function send(Closure $request): Response
    {
        return ($this->send)($request);
    }

    /**
     * A response that must be successful; otherwise the mapped failure.
     *
     * @throws PublishFailed
     */
    public function ensure(Response $response, ?string $notFound = null): Response
    {
        if (! $response->successful()) {
            throw self::failure($response, $notFound);
        }

        return $response;
    }

    /**
     * Run a request made before the Pin exists (media registration, upload, status): nothing can be public
     * yet, so a failure whose outcome is unknown there is safe to retry.
     *
     * @param  Closure(): Response  $request
     *
     * @throws PublishFailed
     */
    public function beforePublish(Closure $request, ?string $notFound = null): Response
    {
        try {
            return $this->ensure($this->send($request), $notFound);
        } catch (PublishFailed $failure) {
            if ($failure->outcomeUnknown) {
                throw new PublishFailed($failure->getMessage(), $failure->errorCode, retryable: true);
            }

            throw $failure;
        }
    }

    /**
     * Pinterest answers errors as `{"code": int, "message": string}`; the HTTP status decides the meaning.
     * 401 and 403 send the account to "needs reauthorization"; 429 is retryable; a 5xx may have been
     * processed, so it is an unknown outcome (the caller decides whether that is safe to retry).
     */
    public static function failure(Response $response, ?string $notFound = null): PublishFailed
    {
        $message = self::clean($response->json('message'), __('Pinterest rejected the request.'));

        return match (true) {
            $response->status() === 401 => PublishFailed::reauthorization(__('Pinterest no longer accepts this connection. Connect the board again.')),
            $response->status() === 403 => PublishFailed::reauthorization(__('Pinterest denied the request: the app or the account lacks the required access (creating Pins needs Pinterest Standard access and the pins:write scope).')),
            $response->status() === 404 => PublishFailed::rejected($notFound ?? $message, 'not_found'),
            $response->status() === 429 => PublishFailed::rateLimited(__('Pinterest is rate limiting requests. The publish will be tried again later.'), self::retryAfter($response)),
            $response->status() === 408, $response->serverError() => PublishFailed::unknownOutcome(
                __('Pinterest did not confirm the request. Check the board before trying again.'), 'platform_error',
            ),
            default => PublishFailed::rejected($message, 'pinterest_'.$response->status()),
        };
    }

    public static function clean(mixed $message, string $fallback): string
    {
        if (! is_string($message) || trim($message) === '') {
            return $fallback;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message) ?? $fallback), 300, '');
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, min(3600, (int) $header)) : null;
    }
}
