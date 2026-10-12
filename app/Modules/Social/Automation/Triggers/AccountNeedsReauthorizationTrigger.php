<?php

declare(strict_types=1);

namespace App\Modules\Social\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Social\Events\AccountNeedsReauthorization;

final class AccountNeedsReauthorizationTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'social.account.needs_reauthorization';
    }

    public static function label(): string
    {
        return 'automation.triggers.social.account.needs_reauthorization';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function event(): string
    {
        return AccountNeedsReauthorization::class;
    }

    public static function requiredPermission(): string
    {
        return 'social.accounts.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['account_id', 'platform'], 'properties' => ['account_id' => ['type' => 'string'], 'platform' => ['type' => 'string']]];
    }
}
