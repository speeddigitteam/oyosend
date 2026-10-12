<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Data;

/**
 * A provider's answer to a send. Three outcomes only.
 */
final readonly class SendResult
{
    public const string ACCEPTED = 'accepted';

    /** A temporary failure: network, rate limit, provider outage. The message is retried with backoff. */
    public const string RETRY = 'retry';

    /** A permanent refusal: not configured, invalid recipient, rejected content. Never retried. */
    public const string REJECTED = 'rejected';

    private function __construct(
        public string $outcome,
        public ?string $providerMessageId,
        public ?string $code,
        public ?string $reason,
    ) {}

    public static function accepted(?string $providerMessageId = null): self
    {
        return new self(self::ACCEPTED, $providerMessageId, null, null);
    }

    public static function retry(string $code, string $reason = ''): self
    {
        return new self(self::RETRY, null, $code, $reason);
    }

    public static function rejected(string $code, string $reason = ''): self
    {
        return new self(self::REJECTED, null, $code, $reason);
    }
}
