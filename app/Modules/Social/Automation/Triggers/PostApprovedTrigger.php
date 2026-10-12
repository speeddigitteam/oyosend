<?php

declare(strict_types=1);

namespace App\Modules\Social\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Social\Events\PostApproved;

final class PostApprovedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'social.post.approved';
    }

    public static function label(): string
    {
        return 'automation.triggers.social.post.approved';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function event(): string
    {
        return PostApproved::class;
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
        return ['type' => 'object', 'required' => ['post_id', 'approved_by'], 'properties' => ['post_id' => ['type' => 'string'], 'approved_by' => ['type' => 'string'], 'submitted_by' => ['type' => ['string', 'null']]]];
    }
}
