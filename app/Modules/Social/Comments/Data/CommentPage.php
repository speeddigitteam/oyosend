<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Data;

/** The result of reading a post's comments: what was returned and, when a read stopped early, why. */
final readonly class CommentPage
{
    public const string OK = 'ok';

    /** @param list<RemoteComment> $comments */
    public function __construct(
        public array $comments = [],
        public string $status = self::OK,
        public ?string $errorCode = null,
        public ?int $retryAfter = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}
