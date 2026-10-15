<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Instagram;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The HTTP helpers of the Instagram publisher. Requests go through the adapter's own client profile and
 * `send()` (a connection that never opened is retryable, a lost answer is an unknown outcome). Tokens
 * travel only in the Authorization header; media URLs only in a form body.
 */
final class InstagramGraph
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
        return rtrim($this->base, '/').'/'.$this->version.'/'.ltrim($path, '/');
    }

    public function request(string $token): PendingRequest
    {
        return ($this->client)()->withToken($token);
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
    public function ensure(Response $response): Response
    {
        if (! $response->successful()) {
            throw InstagramGraphErrors::failure($response);
        }

        return $response;
    }

    /**
     * Run a request that happens before anything is public (a container, a status read): an unknown outcome
     * there is safe to retry, because nothing is published until `media_publish`.
     *
     * @param  Closure(): Response  $request
     *
     * @throws PublishFailed
     */
    public function beforePublish(Closure $request): Response
    {
        try {
            return $this->ensure($this->send($request));
        } catch (PublishFailed $failure) {
            if ($failure->outcomeUnknown) {
                throw new PublishFailed($failure->getMessage(), $failure->errorCode, retryable: true);
            }

            throw $failure;
        }
    }
}
