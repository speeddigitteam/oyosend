<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use Closure;
use LogicException;

final readonly class MediaFile
{
    /**
     * @param  Closure(): string  $reader  Returns the file bytes.
     * @param  (Closure(int, int): string)|null  $ranger  Returns `length` bytes from `offset` without loading the file.
     * @param  (Closure(): string)|null  $publicUrl  Returns a public, expiring (signed) URL to the file, for a platform
     *                                               that fetches media itself (Instagram). Called only when an adapter
     *                                               needs it, because each URL briefly exposes a private file.
     */
    public function __construct(
        public string $name,
        public string $mime,
        public bool $isVideo,
        public ?string $altText,
        private Closure $reader,
        public ?int $sizeBytes = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
        private ?Closure $ranger = null,
        private ?Closure $publicUrl = null,
    ) {}

    public function contents(): string
    {
        return ($this->reader)();
    }

    /** A slice of the file; streams from storage when the caller provided a ranger. */
    public function range(int $offset, int $length): string
    {
        if ($this->ranger !== null) {
            return ($this->ranger)($offset, $length);
        }

        return substr($this->contents(), $offset, $length);
    }

    /** Whether a public, expiring URL can be made for this file. */
    public function hasPublicUrl(): bool
    {
        return $this->publicUrl !== null;
    }

    /**
     * A new public URL that expires after `config('social.media.public_url_minutes')`. Ask for it right
     * before the platform call that needs it, never earlier, and never log or store it.
     *
     * @throws LogicException when the caller built this file without a URL maker
     */
    public function publicUrl(): string
    {
        if ($this->publicUrl === null) {
            throw new LogicException('This media file has no public URL.');
        }

        return ($this->publicUrl)();
    }

    public function info(): MediaInfo
    {
        return new MediaInfo(
            $this->isVideo, $this->mime, $this->sizeBytes ?? 0, $this->width, $this->height, $this->durationMs,
        );
    }
}
