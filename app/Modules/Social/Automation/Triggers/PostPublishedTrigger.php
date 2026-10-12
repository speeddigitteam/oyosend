<?php

declare(strict_types=1);

namespace App\Modules\Social\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Social\Events\PostPublished;

final class PostPublishedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'social.post.published';
    }

    public static function label(): string
    {
        return 'automation.triggers.social.post.published';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function event(): string
    {
        return PostPublished::class;
    }

    public static function requiredPermission(): string
    {
        return 'social.posts.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['post_id', 'target_count'], 'properties' => ['post_id' => ['type' => 'string'], 'target_count' => ['type' => 'integer'], 'published_at' => ['type' => ['string', 'null']], 'campaign_id' => ['type' => ['string', 'null']]]];
    }
}
