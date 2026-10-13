<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/**
 * The facts about one library file that publishing rules need. Width, height and duration are null
 * when the file could not be read (never zero): rules then warn instead of guessing.
 */
final readonly class MediaInfo
{
    public function __construct(
        public bool $isVideo,
        public string $mime,
        public int $sizeBytes,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
    ) {}

    /** Whether a video's length and picture size are both known. */
    public function probed(): bool
    {
        return $this->durationMs !== null && $this->width !== null && $this->height !== null;
    }
}
