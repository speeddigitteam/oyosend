<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Events\PostFailed;
use App\Modules\Social\Events\PostPublished;
use App\Modules\Social\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Derives a queued post's state from its per-account targets and announces the outcome once,
 * when the last target settles.
 */
final class PostRollup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function refresh(string $postId): void
    {
        DB::transaction(function () use ($postId): void {
            $post = Post::query()->lockForUpdate()->find($postId);

            if ($post === null || ! in_array($post->status, [PostStatus::Scheduled, PostStatus::Publishing], true)) {
                return;
            }

            $targets = $post->targets()->get();

            if ($targets->contains(fn ($target): bool => in_array($target->status, [TargetStatus::Publishing, TargetStatus::Processing], true))) {
                $post->update(['status' => PostStatus::Publishing]);

                return;
            }

            if ($targets->contains(fn ($target): bool => $target->status === TargetStatus::Pending)) {
                $post->update(['status' => PostStatus::Scheduled]);

                return;
            }

            $published = $targets->where('status', TargetStatus::Published)->count();
            $failed = $targets->where('status', TargetStatus::Failed)->count();

            if ($failed === 0) {
                $post->fill([
                    'status' => PostStatus::Published,
                    'published_at' => $targets->max('published_at') ?? now(),
                ])->save();
                $this->audit->record('social.post.published', $post, ['target_count' => $published]);
                event(PostPublished::from($post, $published));

                return;
            }

            $post->fill([
                'status' => $published > 0 ? PostStatus::PartiallyFailed : PostStatus::Failed,
                'published_at' => $targets->where('status', TargetStatus::Published)->max('published_at'),
            ])->save();
            $this->audit->record('social.post.failed', $post, ['failed' => $failed, 'published' => $published]);
            event(PostFailed::from($post, $failed, $published));
        });
    }
}
