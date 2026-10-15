<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\ProvidesPostOptions;
use App\Modules\Social\Platforms\SocialPlatforms;

/**
 * The per-account publishing options of a target in their stored form ({@see ProvidesPostOptions}). An account
 * whose platform has no such options never stores any.
 */
final class TargetOptions
{
    public function __construct(private readonly SocialPlatforms $platforms) {}

    /**
     * @param  array<string, mixed>|null  $input  as sent by the composer (validated by the request)
     * @return array<string, mixed>|null
     */
    public function forAccount(SocialAccount $account, ?array $input): ?array
    {
        $platform = $this->platforms->find($account->platform);

        if ($input === null || ! $platform instanceof ProvidesPostOptions) {
            return null;
        }

        return $platform->normalizePostOptions($input);
    }

    /** Whether the account's platform has per-post options the creator must choose. */
    public function supported(string $platformKey): bool
    {
        return $this->platforms->find($platformKey) instanceof ProvidesPostOptions;
    }
}
