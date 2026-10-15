<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\TikTok;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Keeps TikTok's `upload_url` between publisher runs while a large video is uploaded in several runs.
 *
 * The URL carries an upload token, so it is never written to the target row (`remote_meta` holds no
 * secrets), a log or a message: it lives only in the workspace's own (tenant-prefixed) cache, encrypted
 * with the application key, and expires before TikTok's own one-hour validity ends. If it is gone the
 * upload cannot continue and the target fails with "nothing was posted" (an incomplete upload is never
 * published by TikTok).
 */
final class TikTokUploadUrls
{
    /** TikTok's upload URL is valid for one hour after `init`. */
    private const int TTL_SECONDS = 55 * 60;

    public function remember(string $publishId, string $uploadUrl): void
    {
        Cache::put(self::key($publishId), Crypt::encryptString($uploadUrl), self::TTL_SECONDS);
    }

    public function find(string $publishId): ?string
    {
        $stored = Cache::get(self::key($publishId));

        if (! is_string($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function forget(string $publishId): void
    {
        Cache::forget(self::key($publishId));
    }

    private static function key(string $publishId): string
    {
        return 'social:tiktok:upload:'.hash('sha256', $publishId);
    }
}
