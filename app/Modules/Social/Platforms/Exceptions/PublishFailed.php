<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Exceptions;

use RuntimeException;

/**
 * A platform call did not produce a post.
 *
 * `retryable` is true only when the platform provably did not process the request (rate limit,
 * refused connection). `outcomeUnknown` is true when it may have: the caller must then not
 * retry blindly. The message is safe to show people and contains no token.
 */
final class PublishFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'publish_failed',
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $outcomeUnknown = false,
        public readonly bool $reauthorize = false,
    ) {
        parent::__construct($message);
    }

    public static function rateLimited(string $message, ?int $retryAfterSeconds = null): self
    {
        return new self($message, 'rate_limited', retryable: true, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function reauthorization(string $message): self
    {
        return new self($message, 'reauthorization_required', reauthorize: true);
    }

    public static function unknownOutcome(string $message, string $code = 'outcome_unknown'): self
    {
        return new self($message, $code, outcomeUnknown: true);
    }

    public static function rejected(string $message, string $code = 'rejected'): self
    {
        return new self($message, $code);
    }
}
