<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Api;

use RuntimeException;

/**
 * A failed Marketing API call. The message is safe to show and to store: it is our own wording or Meta's
 * error text trimmed and scrubbed of the access token, never a response body.
 */
final class MetaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ErrorKind $kind,
        public readonly string $errorCode = 'meta_error',
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?ApiUsage $usage = null,
    ) {
        parent::__construct($message);
    }

    public function needsReauthorization(): bool
    {
        return $this->kind === ErrorKind::Token;
    }

    /** Whether trying the same request again later could succeed. */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [ErrorKind::RateLimit, ErrorKind::Transient], true);
    }
}
