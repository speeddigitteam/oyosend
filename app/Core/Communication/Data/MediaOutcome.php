<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use App\Core\Communication\Contracts\MediaGateway;

/**
 * The answer of a {@see MediaGateway} call.
 *
 * `reason` when not ok: `module_unavailable`, `provider_not_configured`, `number_unavailable`,
 * `unsupported_media_type` (not on the allow-list), `content_mismatch` (the bytes are not what the type says),
 * `too_large`, `empty_file`, `not_found`, `provider_rejected`, `provider_unreachable`, `provider_unavailable`
 * (a temporary refusal; retry later).
 */
final readonly class MediaOutcome
{
    public function __construct(
        public bool $ok,
        public ?string $reason = null,
        public ?StoredMedia $media = null,
    ) {}

    public static function ok(StoredMedia $media): self
    {
        return new self(true, null, $media);
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }
}
