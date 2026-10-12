<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use Closure;

final readonly class MediaFile
{
    /** @param Closure(): string $reader Returns the file bytes. */
    public function __construct(
        public string $name,
        public string $mime,
        public bool $isVideo,
        public ?string $altText,
        private Closure $reader,
    ) {}

    public function contents(): string
    {
        return ($this->reader)();
    }
}
