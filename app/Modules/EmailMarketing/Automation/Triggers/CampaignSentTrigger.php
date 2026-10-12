<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\EmailMarketing\Events\CampaignSent;

final class CampaignSentTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'email_marketing.campaign.sent';
    }

    public static function label(): string
    {
        return 'automation.triggers.email_marketing.campaign.sent';
    }

    public static function module(): string
    {
        return 'email_marketing';
    }

    public static function event(): string
    {
        return CampaignSent::class;
    }

    public static function requiredPermission(): string
    {
        return 'email_marketing.campaigns.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['campaign_id', 'list_id'],
            'properties' => [
                'campaign_id' => ['type' => 'string'],
                'list_id' => ['type' => 'string'],
                'audience' => ['type' => 'integer'],
                'sent' => ['type' => 'integer'],
                'blocked' => ['type' => 'integer'],
                'skipped' => ['type' => 'integer'],
                'finished_at' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
