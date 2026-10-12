<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Events;

use App\Modules\EmailMarketing\Models\Campaign;

/** Every planned email of a campaign was handed to the platform (or skipped); the campaign is finished. */
final class CampaignSent extends EmailMarketingEvent
{
    public static function from(Campaign $campaign, int $sent, int $blocked, int $skipped): self
    {
        return new self([
            'campaign_id' => $campaign->id,
            'list_id' => $campaign->list_id,
            'audience' => $campaign->audience_total,
            'sent' => $sent,
            'blocked' => $blocked,
            'skipped' => $skipped,
            'finished_at' => $campaign->finished_at?->toIso8601String(),
        ]);
    }

    public static function name(): string
    {
        return 'email_marketing.campaign.sent';
    }
}
