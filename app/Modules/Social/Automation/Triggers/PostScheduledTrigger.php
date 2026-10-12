<?php

declare(strict_types=1);

namespace App\Modules\Social\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Social\Events\PostScheduled;

final class PostScheduledTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'social.post.scheduled';
    }

    public static function label(): string
    {
        return 'automation.triggers.social.post.scheduled';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function event(): string
    {
        return PostScheduled::class;
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
        return ['type' => 'object', 'required' => ['post_id', 'scheduled_for', 'target_count'], 'properties' => ['post_id' => ['type' => 'string'], 'scheduled_for' => ['type' => 'string'], 'target_count' => ['type' => 'integer'], 'campaign_id' => ['type' => ['string', 'null']]]];
    }
}
