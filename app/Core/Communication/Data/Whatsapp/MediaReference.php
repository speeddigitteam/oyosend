<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Whatsapp;

use App\Core\Communication\Contracts\MediaGateway;
use InvalidArgumentException;

/**
 * A file to send, by reference only: never a path and never a URL.
 *
 * Exactly one of `mediaId` (an id from {@see MediaGateway::store()}, a file held
 * in the workspace's own storage; the platform uploads it to the provider at send time) or `providerMediaId` (an
 * id the provider already returned, valid for about 30 days).
 */
final readonly class MediaReference
{
    public function __construct(
        public WhatsappMediaType $type,
        public ?string $mediaId = null,
        public ?string $providerMediaId = null,
        public ?string $filename = null,
    ) {
        if (($mediaId === null) === ($providerMediaId === null)) {
            throw new InvalidArgumentException('A media reference names a stored media id or a provider media id, not both and not neither.');
        }

        foreach ([$mediaId, $providerMediaId] as $id) {
            if ($id !== null && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) !== 1) {
                throw new InvalidArgumentException('A media id has 1 to 100 letters, digits, dashes or underscores.');
            }
        }

        if ($filename !== null && ($filename === '' || mb_strlen($filename) > 240 || preg_match('/[\x00-\x1F\/\\\\]/', $filename) === 1)) {
            throw new InvalidArgumentException('A media file name has 1 to 240 characters and no path separators.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'media_id' => $this->mediaId,
            'provider_media_id' => $this->providerMediaId,
            'filename' => $this->filename,
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            WhatsappMediaType::from((string) ($data['type'] ?? '')),
            isset($data['media_id']) ? (string) $data['media_id'] : null,
            isset($data['provider_media_id']) ? (string) $data['provider_media_id'] : null,
            isset($data['filename']) ? (string) $data['filename'] : null,
        );
    }
}
