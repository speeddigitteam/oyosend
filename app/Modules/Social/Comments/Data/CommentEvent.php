<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Data;

/** One comment change from a platform webhook, reduced to the fields we use. `verb` is add, edited or remove. */
final readonly class CommentEvent
{
    public function __construct(
        public string $pageId,
        public string $verb,
        public RemoteComment $comment,
        public string $key,
    ) {}
}
