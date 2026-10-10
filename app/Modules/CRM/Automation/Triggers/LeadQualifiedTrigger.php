<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\CRM\Events\LeadQualified;

final class LeadQualifiedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'crm.lead.qualified';
    }

    public static function label(): string
    {
        return 'automation.triggers.crm.lead.qualified';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function event(): string
    {
        return LeadQualified::class;
    }

    public static function requiredPermission(): string
    {
        return 'crm.leads.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['minimum_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['lead_id', 'score'], 'properties' => ['lead_id' => ['type' => 'string'], 'score' => ['type' => 'integer']]];
    }
}
