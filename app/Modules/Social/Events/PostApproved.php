<?php

declare(strict_types=1);

namespace App\Modules\Social\Events;

use App\Modules\Social\Models\Post;

/** A post was approved by someone other than its author and submitter. */
final class PostApproved extends SocialEvent
{
    public static function from(Post $post): self
    {
        return new self(['post_id' => $post->id, 'approved_by' => $post->approved_by, 'submitted_by' => $post->submitted_by]);
    }

    public static function name(): string
    {
        return 'social.post.approved';
    }
}
