<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * Tokens returned by a platform. Redacts itself for var_dump, JSON and serialisation so an
 * accidental log or response cannot leak it.
 */
final readonly class TokenSet implements JsonSerializable
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $refreshExpiresAt = null,
        public array $scopes = [],
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'refreshToken' => $this->refreshToken === null ? null : '[redacted]'];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['accessToken' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return ['redacted' => true];
    }
}
