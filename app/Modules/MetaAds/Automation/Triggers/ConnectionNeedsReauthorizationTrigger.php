<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\MetaAds\Events\ConnectionNeedsReauthorization;

final class ConnectionNeedsReauthorizationTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'meta_ads.connection.needs_reauthorization';
    }

    public static function label(): string
    {
        return 'automation.triggers.meta_ads.connection.needs_reauthorization';
    }

    public static function module(): string
    {
        return 'meta_ads';
    }

    public static function event(): string
    {
        return ConnectionNeedsReauthorization::class;
    }

    public static function requiredPermission(): string
    {
        return 'meta_ads.accounts.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['connection_id'], 'properties' => ['connection_id' => ['type' => 'string']]];
    }
}
