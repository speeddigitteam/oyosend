<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** A file in the workspace's media store. Metadata only: bytes come from `MediaGateway::read()`. */
final readonly class StoredMedia
{
    /**
     * @param  string  $id  The media id to use in a `MediaReference`.
     * @param  string  $type  `image`, `document`, `audio`, `video` or `sticker`.
     * @param  string  $mimeType  The SNIFFED type.
     * @param  string  $direction  `outbound` (stored for sending) or `inbound` (downloaded from a customer).
     * @param  string|null  $providerMediaId  The provider's id once uploaded (outbound) or received (inbound).
     * @param  string|null  $numberId  The workspace number the provider id belongs to.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
        public ?string $filename,
        public string $direction,
        public ?string $providerMediaId = null,
        public ?string $numberId = null,
    ) {}
}
