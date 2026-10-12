<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/**
 * The content handed to a platform. Media bytes are read lazily so a text post never touches storage.
 */
final readonly class PublishPayload
{
    /** @param list<MediaFile> $media */
    public function __construct(
        public string $text,
        public ?string $linkUrl = null,
        public array $media = [],
        public string $idempotencyKey = '',
    ) {}
}
