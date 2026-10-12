<?php

declare(strict_types=1);

namespace App\Modules\Social\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Social\Events\PostFailed;

final class PostFailedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'social.post.failed';
    }

    public static function label(): string
    {
        return 'automation.triggers.social.post.failed';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function event(): string
    {
        return PostFailed::class;
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
        return ['type' => 'object', 'required' => ['post_id', 'status', 'failed_count', 'published_count'], 'properties' => ['post_id' => ['type' => 'string'], 'status' => ['type' => 'string'], 'failed_count' => ['type' => 'integer'], 'published_count' => ['type' => 'integer']]];
    }
}
