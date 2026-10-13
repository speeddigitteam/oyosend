<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Data;

/**
 * What a provider adapter answers to a media upload or download. Holds no credential. For an upload
 * `providerMediaId` is set; for a download `bytes`, `mime` (as the provider declared it), `sha256`.
 */
final readonly class MediaTransfer
{
    public const string TOO_LARGE = 'too_large';

    public const string UNREACHABLE = 'provider_unreachable';

    public const string UNAVAILABLE = 'provider_unavailable';

    public const string REJECTED = 'provider_rejected';

    public const string AUTH = 'provider_authentication_failed';

    private function __construct(
        public bool $ok,
        public ?string $reason = null,
        public ?string $providerMediaId = null,
        public ?string $bytes = null,
        public ?string $mime = null,
        public ?string $sha256 = null,
    ) {}

    public static function uploaded(string $providerMediaId): self
    {
        return new self(true, null, $providerMediaId);
    }

    public static function downloaded(string $bytes, ?string $mime, ?string $sha256, ?string $providerMediaId = null): self
    {
        return new self(true, null, $providerMediaId, $bytes, $mime, $sha256);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}
