<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/** Figures a platform returned for one item. A null figure was not returned and is never treated as zero. */
final readonly class PostMetricsSnapshot
{
    public function __construct(
        public ?int $impressions = null,
        public ?int $reach = null,
        public ?int $likes = null,
        public ?int $comments = null,
        public ?int $shares = null,
        public ?int $clicks = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->impressions === null && $this->reach === null && $this->likes === null
            && $this->comments === null && $this->shares === null && $this->clicks === null;
    }
}
