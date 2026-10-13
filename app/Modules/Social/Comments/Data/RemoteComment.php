<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Data;

use App\Modules\Social\Comments\Ingest\CommentIngestor;
use Carbon\CarbonImmutable;

/** One comment as a platform reported it. Text is raw here; {@see CommentIngestor} cleans it. */
final readonly class RemoteComment
{
    public function __construct(
        public string $id,
        public ?string $postId,
        public ?string $parentId,
        public ?string $authorId,
        public ?string $authorName,
        public ?string $message,
        public ?CarbonImmutable $createdAt = null,
        public int $likeCount = 0,
        public bool $hasAttachment = false,
        public bool $isHidden = false,
        public ?string $permalink = null,
    ) {}
}
