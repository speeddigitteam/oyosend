<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/** What an adapter needs to act as one account. Redacts itself like {@see TokenSet}. */
final readonly class AccountCredentials
{
    public function __construct(
        public string $externalId,
        public string $accessToken,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['externalId' => $this->externalId, 'accessToken' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return ['redacted' => true];
    }
}
