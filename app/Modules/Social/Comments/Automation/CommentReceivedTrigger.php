<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Automation;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Social\Comments\Events\CommentReceived;

/** Fires when a new audience comment is received. Carries ids only; workflows read the comment through actions. */
final class CommentReceivedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'social.comment.received';
    }

    public static function label(): string
    {
        return 'automation.triggers.social.comment.received';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function event(): string
    {
        return CommentReceived::class;
    }

    public static function requiredPermission(): string
    {
        return 'social.comments.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['comment_id', 'account_id', 'platform', 'is_reply'],
            'properties' => [
                'comment_id' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'post_id' => ['type' => ['string', 'null']],
                'platform' => ['type' => 'string'],
                'is_reply' => ['type' => 'boolean'],
            ],
        ];
    }
}
