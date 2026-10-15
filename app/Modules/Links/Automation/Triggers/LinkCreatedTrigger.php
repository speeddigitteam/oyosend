<?php

declare(strict_types=1);

namespace App\Modules\Links\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Links\Events\LinkCreated;

final class LinkCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'links.link.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.links.link.created';
    }

    public static function module(): string
    {
        return 'links';
    }

    public static function event(): string
    {
        return LinkCreated::class;
    }

    public static function requiredPermission(): string
    {
        return 'links.links.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['link_id', 'kind', 'title'], 'properties' => [
            'link_id' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'folder' => ['type' => ['string', 'null']],
            'owner_id' => ['type' => ['string', 'null']],
            'is_pinned' => ['type' => 'boolean'],
        ]];
    }
}
