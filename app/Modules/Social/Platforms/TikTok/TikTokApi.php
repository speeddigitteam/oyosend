<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\TikTok;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The HTTP helpers the TikTok publisher shares with the adapter. Requests go through the adapter's own
 * client profile and `send()`, so a connection that never opened stays retryable and a lost answer stays
 * an unknown outcome. The access token travels only in the Authorization header.
 */
final class TikTokApi
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
     * POST a JSON body to a Content Posting endpoint and return its `data` object.
     *
     * `$read` marks a call that changes nothing on TikTok (creator info, status): a lost answer there is
     * safe to retry. Otherwise a lost answer or a server error is an unknown outcome.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PublishFailed
     */
    public function call(string $accessToken, string $path, array $body, bool $read): array
    {
        $json = json_encode($body === [] ? new \stdClass : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $response = $this->send(fn (): Response => $this->request()
                ->withToken($accessToken)
                ->withBody($json, 'application/json; charset=UTF-8')
                ->post($this->url($path)));

            if (! self::ok($response)) {
                throw TikTokErrors::failure($response);
            }
        } catch (PublishFailed $failure) {
            // TikTok's own text is shown to people: make sure it never echoes the token back.
            $message = str_replace($accessToken, '[redacted]', $failure->getMessage());

            if ($read && $failure->outcomeUnknown) {
                throw new PublishFailed($message, $failure->errorCode, retryable: true);
            }

            if ($message !== $failure->getMessage()) {
                throw new PublishFailed(
                    $message, $failure->errorCode, $failure->retryable, $failure->retryAfterSeconds,
                    $failure->outcomeUnknown, $failure->reauthorize,
                );
            }

            throw $failure;
        }

        $data = $response->json('data');
        $fields = [];

        foreach (is_array($data) ? $data : [] as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    /** A successful TikTok answer: HTTP 2xx and an error code of `ok` (or none). */
    public static function ok(Response $response): bool
    {
        $code = $response->json('error.code');

        return $response->successful() && ($code === null || $code === 'ok' || $code === '');
    }
}
