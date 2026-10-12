<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\SmsMarketing\Events\CampaignLaunched;

final class CampaignLaunchedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'sms_marketing.campaign.launched';
    }

    public static function label(): string
    {
        return 'automation.triggers.sms_marketing.campaign.launched';
    }

    public static function module(): string
    {
        return 'sms_marketing';
    }

    public static function event(): string
    {
        return CampaignLaunched::class;
    }

    public static function requiredPermission(): string
    {
        return 'sms_marketing.campaigns.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['campaign_id', 'status'],
            'properties' => [
                'campaign_id' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'scheduled_for' => ['type' => ['string', 'null']],
                'estimated_segments' => ['type' => 'integer'],
            ],
        ];
    }
}
