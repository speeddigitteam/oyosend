<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Events;

use App\Modules\SmsMarketing\Models\Campaign;

/** A campaign was started now or scheduled. */
final class CampaignLaunched extends SmsMarketingEvent
{
    public static function from(Campaign $campaign): self
    {
        return new self([
            'campaign_id' => $campaign->id,
            'status' => $campaign->status->value,
            'scheduled_for' => $campaign->scheduled_for?->toIso8601String(),
            'estimated_segments' => $campaign->estimated_segments,
        ]);
    }

    public static function name(): string
    {
        return 'sms_marketing.campaign.launched';
    }
}
