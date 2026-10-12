<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Social\Actions\CommentManager;
use App\Modules\Social\Http\Requests\CommentRequest;
use App\Modules\Social\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Team comments on a post. Visible to whoever may view the post. */
final class CommentController
{
    public function __construct(
        private readonly CommentManager $comments,
        private readonly SocialSupport $support,
    ) {}

    public function store(CommentRequest $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, PostController::VIEW);
        $this->comments->add($record, $this->support->membership($actor), (string) $request->validated('body'));
        Toast::success(__('Comment added.'));

        return back();
    }

    public function destroy(Request $request, string $post, string $comment): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, PostController::VIEW);
        $row = Comment::query()->where('post_id', $record->id)->findOrFail($comment);

        // Only the author removes a comment, or a reviewer who holds the approve permission.
        abort_unless(
            $row->author_id === $this->support->membership($actor)->id || $actor->can(PostController::APPROVE),
            403,
        );

        $this->comments->delete($record, $row);
        Toast::success(__('Comment deleted.'));

        return back();
    }
}
