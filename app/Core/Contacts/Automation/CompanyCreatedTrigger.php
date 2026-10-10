<?php

declare(strict_types=1);

namespace App\Core\Contacts\Automation;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Core\Contacts\Events\CompanyCreated;

/**
 * "A company was added". Describes the trigger; nothing executes workflows
 * until the engine is built (Phase 10).
 */
final class CompanyCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'core.company.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.core.company.created';
    }

    public static function module(): string
    {
        return 'core';
    }

    public static function event(): string
    {
        return CompanyCreated::class;
    }

    public static function requiredPermission(): string
    {
        return 'core.contacts.view';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['company_id', 'name'],
            'properties' => [
                'company_id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'email' => ['type' => ['string', 'null'], 'format' => 'email'],
                'phone' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
