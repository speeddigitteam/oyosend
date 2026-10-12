<?php

declare(strict_types=1);

namespace App\Modules\Social\Events;

use App\Modules\Social\Models\Post;

/** Every account of a post was published. */
final class PostPublished extends SocialEvent
{
    public static function from(Post $post, int $targetCount): self
    {
        return new self(['post_id' => $post->id, 'target_count' => $targetCount, 'published_at' => $post->published_at?->toIso8601String(), 'campaign_id' => $post->campaign_id]);
    }

    public static function name(): string
    {
        return 'social.post.published';
    }
}
