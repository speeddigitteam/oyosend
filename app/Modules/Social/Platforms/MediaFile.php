<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use Closure;

final readonly class MediaFile
{
    /**
     * @param  Closure(): string  $reader  Returns the file bytes.
     * @param  (Closure(int, int): string)|null  $ranger  Returns `length` bytes from `offset` without loading the file.
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

    public function info(): MediaInfo
    {
        return new MediaInfo(
            $this->isVideo, $this->mime, $this->sizeBytes ?? 0, $this->width, $this->height, $this->durationMs,
        );
    }
}
