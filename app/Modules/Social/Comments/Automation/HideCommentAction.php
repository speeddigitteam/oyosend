<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Automation;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Social\Automation\ActionReceiptStore;
use App\Modules\Social\Comments\Actions\CommentActions;
use App\Modules\Social\Comments\Models\AudienceComment;

/** Hides (or unhides) an audience comment on the platform. Idempotent per key and by nature. */
final class HideCommentAction implements ActionDefinition
{
    public function __construct(
        private readonly CommentActions $comments,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'social.comment.hide';
    }

    public static function label(): string
    {
        return 'automation.actions.social.comment.hide';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function requiredPermission(): string
    {
        return 'social.comments.moderate';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['comment_id'],
            'properties' => [
                'comment_id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                'hidden' => ['type' => 'boolean'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['comment_id', 'hidden'],
            'properties' => ['comment_id' => ['type' => 'string'], 'hidden' => ['type' => 'boolean']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $comment = AudienceComment::query()->with('account')->findOrFail((string) ($input['comment_id'] ?? ''));
            $hidden = ($input['hidden'] ?? true) !== false;
            $this->comments->setHidden($comment, $hidden, null);

            return ['comment_id' => $comment->id, 'hidden' => $hidden];
        });
    }
}
