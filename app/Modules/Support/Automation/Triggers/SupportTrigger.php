<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;

/** Shared shape of the Support triggers: no configuration, ticket facts as output. */
abstract class SupportTrigger implements TriggerDefinition
{
    public static function label(): string
    {
        return 'automation.triggers.'.static::key();
    }

    public static function module(): string
    {
        return 'support';
    }

    public static function requiredPermission(): string
    {
        return 'support.tickets.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ticket_id', 'number'],
            'properties' => [
                'ticket_id' => ['type' => 'string'],
                'number' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'priority' => ['type' => 'string'],
                'channel' => ['type' => 'string'],
                'queue_id' => ['type' => ['string', 'null']],
                'category_id' => ['type' => ['string', 'null']],
                'contact_id' => ['type' => ['string', 'null']],
                'company_id' => ['type' => ['string', 'null']],
                'owner_id' => ['type' => ['string', 'null']],
                ...static::extraProperties(),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected static function extraProperties(): array
    {
        return [];
    }
}
