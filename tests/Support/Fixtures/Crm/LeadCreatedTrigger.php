<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Crm;

use App\Core\Automation\Contracts\TriggerDefinition;

final class LeadCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'crm.lead.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.crm.lead.created';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function event(): ?string
    {
        return null;
    }

    public static function requiredPermission(): ?string
    {
        return 'crm.leads.edit';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object'];
    }
}
