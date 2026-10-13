<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use App\Core\Communication\Contracts\MediaGateway;

/**
 * An inbound media message's provider facts. The bytes are NOT here: ask
 * {@see MediaGateway::download()} with `providerMediaId` (provider media ids
 * are only valid for a short time).
 */
final readonly class InboundMedia
{
    /**
     * @param  string  $type  `image`, `document`, `audio`, `video` or `sticker`.
     * @param  string|null  $mimeType  As declared by the sender; the platform sniffs the bytes on download.
     * @param  string|null  $sha256  The provider's checksum, when given.
     * @param  string|null  $filename  A document's file name as sent (untrusted; never use it as a path).
     * @param  bool  $voice  An audio message recorded as a voice note.
     * @param  int|null  $sizeBytes  Only when the provider reported it (the webhook does not; the download does).
     */
    public function __construct(
        public string $type,
        public string $providerMediaId,
        public ?string $mimeType = null,
        public ?string $sha256 = null,
        public ?string $caption = null,
        public ?string $filename = null,
        public bool $voice = false,
        public ?int $sizeBytes = null,
    ) {}
}
