<?php

declare(strict_types=1);

namespace App\Core\Contacts\Automation;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Core\Contacts\Events\ContactCreated;

/**
 * "A contact was added". Describes the trigger; nothing executes workflows
 * until the engine is built (Phase 10).
 */
final class ContactCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'core.contact.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.core.contact.created';
    }

    public static function module(): string
    {
        return 'core';
    }

    public static function event(): string
    {
        return ContactCreated::class;
    }

    public static function requiredPermission(): string
    {
        return 'core.contacts.view';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'company_id' => [
                    'type' => 'string',
                    'description' => 'Only run for contacts of this company. Empty means any contact.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['contact_id', 'name'],
            'properties' => [
                'contact_id' => ['type' => 'string'],
                'company_id' => ['type' => ['string', 'null']],
                'name' => ['type' => 'string'],
                'email' => ['type' => ['string', 'null'], 'format' => 'email'],
                'phone' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
