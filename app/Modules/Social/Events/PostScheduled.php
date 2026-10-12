<?php

declare(strict_types=1);

namespace App\Modules\Social\Events;

use App\Modules\Social\Models\Post;

/** A post was queued for publishing at its scheduled time. */
final class PostScheduled extends SocialEvent
{
    public static function from(Post $post): self
    {
        return new self(['post_id' => $post->id, 'scheduled_for' => $post->scheduled_for?->toIso8601String(), 'target_count' => $post->targets->count(), 'campaign_id' => $post->campaign_id]);
    }

    public static function name(): string
    {
        return 'social.post.scheduled';
    }
}
