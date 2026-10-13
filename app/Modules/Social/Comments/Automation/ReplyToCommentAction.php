<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Automation;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Social\Automation\ActionReceiptStore;
use App\Modules\Social\Comments\Actions\CommentActions;
use App\Modules\Social\Comments\Models\AudienceComment;

/**
 * Replies publicly to an audience comment. Idempotent per key: a retried step returns the first reply's id and
 * never posts twice. It runs as the workflow's publishing member, whose permission the engine re-checked.
 */
final class ReplyToCommentAction implements ActionDefinition
{
    public function __construct(
        private readonly CommentActions $comments,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'social.comment.reply';
    }

    public static function label(): string
    {
        return 'automation.actions.social.comment.reply';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function requiredPermission(): string
    {
        return 'social.comments.reply';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['comment_id', 'message'],
            'properties' => [
                'comment_id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['comment_id', 'reply_id'],
            'properties' => ['comment_id' => ['type' => 'string'], 'reply_id' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $comment = AudienceComment::query()->with('account')->findOrFail((string) ($input['comment_id'] ?? ''));
            $reply = $this->comments->reply($comment, (string) ($input['message'] ?? ''), null);

            return ['comment_id' => $comment->id, 'reply_id' => $reply->id];
        });
    }
}
