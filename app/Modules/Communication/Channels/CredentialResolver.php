<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels;

use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Channels\Data\ChannelCredentials;

/**
 * Turns a workspace's `credential_ref` into a secret. The secret lives in the environment, behind
 * `config('communication.credentials')`; a workspace can only name an entry the operator made available to it.
 */
final class CredentialResolver
{
    public const string PATTERN = '/^[a-z0-9_]{1,60}$/';

    public function __construct(private readonly TenantContext $context) {}

    public function resolve(?string $reference): ?ChannelCredentials
    {
        $entry = $this->entry($reference);
        $secret = $entry['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? new ChannelCredentials($secret) : null;
    }

    /** Whether the reference names a non-empty secret this workspace may use. */
    public function isAvailable(?string $reference): bool
    {
        return $this->resolve($reference) !== null;
    }

    /**
     * Reference names this workspace may choose from (names only, never values).
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [];

        foreach (array_keys((array) config('communication.credentials', [])) as $name) {
            if (is_string($name) && $this->isAvailable($name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /** @return array{secret?: mixed, tenants?: mixed}|null */
    private function entry(?string $reference): ?array
    {
        if ($reference === null || preg_match(self::PATTERN, $reference) !== 1) {
            return null;
        }

        $entry = config('communication.credentials.'.$reference);

        if (! is_array($entry)) {
            return null;
        }

        $tenants = $entry['tenants'] ?? null;

        if ($tenants !== null && (! is_array($tenants) || ! in_array($this->context->require()->slug, $tenants, true))) {
            return null;
        }

        return $entry;
    }
}
