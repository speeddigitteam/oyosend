<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The few HTTP helpers the Facebook publishers share. Requests go through the adapter's own client
 * profile and `send()` (so connection failures keep the same retryable / unknown-outcome meaning).
 * Tokens travel only in the Authorization header.
 */
final class FacebookGraph
{
    /**
     * @param  Closure(): PendingRequest  $client
     * @param  Closure(Closure(): Response): Response  $send
     */
    public function __construct(
        private readonly Closure $client,
        private readonly Closure $send,
        private readonly string $version,
    ) {}

    public function url(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version.'/'.ltrim($path, '/');
    }

    public function uploadUrl(string $videoId): string
    {
        return 'https://rupload.facebook.com/video-upload/'.$this->version.'/'.rawurlencode($videoId);
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
    public function ensure(Response $response): Response
    {
        if (! $response->successful()) {
            throw FacebookGraphErrors::failure($response);
        }

        return $response;
    }

    /**
     * Run a request that happens before anything is public: a failure whose outcome is unknown there is
     * safe to retry, because the platform cannot have published.
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
