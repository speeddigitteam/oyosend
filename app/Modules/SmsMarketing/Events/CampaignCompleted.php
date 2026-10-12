<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Events;

use App\Modules\SmsMarketing\Models\Campaign;

/** Every recipient of a campaign reached an outcome (sent, delivered, failed, blocked or skipped). */
final class CampaignCompleted extends SmsMarketingEvent
{
    /** @param array{recipients: int, sent: int, delivered: int, failed: int, blocked: int, skipped: int} $counts */
    public static function from(Campaign $campaign, array $counts): self
    {
        return new self([
            'campaign_id' => $campaign->id,
            'recipients' => $counts['recipients'],
            'sent' => $counts['sent'],
            'delivered' => $counts['delivered'],
            'failed' => $counts['failed'],
            'blocked' => $counts['blocked'],
            'skipped' => $counts['skipped'],
        ]);
    }

    public static function name(): string
    {
        return 'sms_marketing.campaign.completed';
    }
}
