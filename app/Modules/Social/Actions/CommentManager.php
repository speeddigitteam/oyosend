<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal team comments on a post. They never leave the workspace. */
final class CommentManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function add(Post $post, TenantMembership $author, string $body): Comment
    {
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 2000) {
            throw ValidationException::withMessages(['body' => __('Write a comment of up to 2000 characters.')]);
        }

        return DB::transaction(function () use ($post, $author, $body): Comment {
            $comment = Comment::query()->create(['post_id' => $post->id, 'author_id' => $author->id, 'body' => $body]);
            $this->audit->record('social.comment.added', $post, ['comment_id' => $comment->id]);

            return $comment;
        });
    }

    public function delete(Post $post, Comment $comment): void
    {
        DB::transaction(function () use ($post, $comment): void {
            $comment->delete();
            $this->audit->record('social.comment.deleted', $post, ['comment_id' => $comment->id]);
        });
    }
}
