<?php

declare(strict_types=1);

namespace App\Modules\Social\Events;

use App\Modules\Social\Models\Post;

/** A post failed on at least one account after its attempts were used. */
final class PostFailed extends SocialEvent
{
    public static function from(Post $post, int $failed, int $published): self
    {
        return new self(['post_id' => $post->id, 'status' => $post->status->value, 'failed_count' => $failed, 'published_count' => $published]);
    }

    public static function name(): string
    {
        return 'social.post.failed';
    }
}
