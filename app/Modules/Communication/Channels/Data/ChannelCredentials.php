<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Data;

use LogicException;
use SensitiveParameter;

/**
 * A resolved provider secret, held in memory only for one send.
 *
 * It cannot be serialized, dumped, cast to string or JSON-encoded, so it cannot reach a queue payload, a log
 * line or an exception message by accident.
 */
final class ChannelCredentials
{
    public function __construct(#[SensitiveParameter] private readonly string $secret) {}

    public function secret(): string
    {
        return $this->secret;
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Credentials are never serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Credentials are never serialized.');
    }

    public function __toString(): string
    {
        return '[credentials]';
    }
}
