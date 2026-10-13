<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\CRM\Events\LeadCreated;

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

    public static function event(): string
    {
        return LeadCreated::class;
    }

    public static function requiredPermission(): string
    {
        return 'crm.leads.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['source_id' => ['type' => ['string', 'null']]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['lead_id', 'title', 'score'], 'properties' => ['lead_id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']], 'owner_id' => ['type' => ['string', 'null']], 'score' => ['type' => 'integer'], 'source_id' => ['type' => ['string', 'null']], 'source_key' => ['type' => ['string', 'null']]]];
    }
}
