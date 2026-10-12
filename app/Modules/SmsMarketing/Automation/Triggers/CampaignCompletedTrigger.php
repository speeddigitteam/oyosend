<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\SmsMarketing\Events\CampaignCompleted;

final class CampaignCompletedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'sms_marketing.campaign.completed';
    }

    public static function label(): string
    {
        return 'automation.triggers.sms_marketing.campaign.completed';
    }

    public static function module(): string
    {
        return 'sms_marketing';
    }

    public static function event(): string
    {
        return CampaignCompleted::class;
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
            'required' => ['campaign_id', 'recipients', 'sent', 'delivered', 'failed', 'blocked', 'skipped'],
            'properties' => [
                'campaign_id' => ['type' => 'string'],
                'recipients' => ['type' => 'integer'],
                'sent' => ['type' => 'integer'],
                'delivered' => ['type' => 'integer'],
                'failed' => ['type' => 'integer'],
                'blocked' => ['type' => 'integer'],
                'skipped' => ['type' => 'integer'],
            ],
        ];
    }
}
