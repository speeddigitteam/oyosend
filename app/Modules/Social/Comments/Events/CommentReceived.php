<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Events;

use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Events\SocialEvent;

/** A new audience comment arrived on a connected account. Ids and scalars only: never the comment text. */
final class CommentReceived extends SocialEvent
{
    public static function from(AudienceComment $comment): self
    {
        return new self([
            'comment_id' => $comment->id,
            'account_id' => $comment->account_id,
            'post_id' => $comment->post_id,
            'platform' => $comment->platform,
            'is_reply' => $comment->parent_id !== null,
        ]);
    }

    public static function name(): string
    {
        return 'social.comment.received';
    }
}
