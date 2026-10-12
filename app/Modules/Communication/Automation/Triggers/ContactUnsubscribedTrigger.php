<?php

declare(strict_types=1);

namespace App\Modules\Communication\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Communication\Events\ContactUnsubscribed;

final class ContactUnsubscribedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'communication.contact.unsubscribed';
    }

    public static function label(): string
    {
        return 'automation.triggers.communication.contact.unsubscribed';
    }

    public static function module(): string
    {
        return 'communication';
    }

    public static function event(): string
    {
        return ContactUnsubscribed::class;
    }

    public static function requiredPermission(): string
    {
        return 'communication.audience.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['channel'], 'properties' => ['contact_id' => ['type' => ['string', 'null']], 'channel' => ['type' => 'string'], 'method' => ['type' => 'string']]];
    }
}
