<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Api;

use Carbon\CarbonImmutable;

/** An access token Meta issued. Never serialised, logged or put in a message; use it once and store it encrypted. */
final readonly class TokenGrant
{
    public function __construct(
        public string $accessToken,
        public ?CarbonImmutable $expiresAt,
    ) {}

    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'expiresAt' => $this->expiresAt?->toIso8601String()];
    }
}
