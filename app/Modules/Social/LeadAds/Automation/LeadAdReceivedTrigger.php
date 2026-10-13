<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Automation;

use App\Core\Automation\Contracts\TriggerDefinition;

final class LeadAdReceivedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'social.lead_ad.received';
    }

    public static function label(): string
    {
        return 'automation.triggers.social.lead_ad.received';
    }

    public static function module(): string
    {
        return 'social';
    }

    public static function event(): string
    {
        return LeadAdReceived::class;
    }

    public static function requiredPermission(): string
    {
        return 'social.leadads.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['lead_ad_id', 'account_id'],
            'properties' => [
                'lead_ad_id' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'form_id' => ['type' => ['string', 'null']],
                'crm_contact_id' => ['type' => ['string', 'null']],
                'crm_lead_id' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
