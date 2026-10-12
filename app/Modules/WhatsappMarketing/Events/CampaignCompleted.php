<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Events;

/** Every recipient of a campaign was handed to the outbox or settled. Payload: `campaign_id`, `total`, `queued`, `blocked`, `failed`, `skipped`. */
final class CampaignCompleted extends WhatsappEvent
{
    public static function name(): string
    {
        return 'whatsapp_marketing.campaign.completed';
    }
}
