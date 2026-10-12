<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Triggers;

use App\Modules\WhatsappMarketing\Events\CampaignCompleted;

/** Every recipient of a campaign was handed to the outbox or settled. */
final class CampaignCompletedTrigger extends WhatsappTrigger
{
    public static function key(): string
    {
        return 'whatsapp_marketing.campaign.completed';
    }

    public static function event(): string
    {
        return CampaignCompleted::class;
    }

    public static function requiredPermission(): string
    {
        return 'whatsapp_marketing.campaigns.view';
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['campaign_id', 'total'],
            'properties' => [
                'campaign_id' => ['type' => 'string'],
                'total' => ['type' => 'integer'],
                'queued' => ['type' => 'integer'],
                'blocked' => ['type' => 'integer'],
                'failed' => ['type' => 'integer'],
                'skipped' => ['type' => 'integer'],
            ],
        ];
    }
}
