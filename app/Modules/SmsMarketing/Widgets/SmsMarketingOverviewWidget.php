<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Widgets;

use App\Core\Access\RecordAccess;
use App\Core\Billing\UsageLimits;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Identity\Models\User;
use App\Modules\SmsMarketing\Actions\RecipientStatusApplier;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Models\Campaign;
use Illuminate\Support\Carbon;

/**
 * Dashboard widget, shown with `sms_marketing.campaigns.view`: campaigns running or waiting (narrowed to the
 * viewer's record scope), the plan's segment allowance and whether the SMS channel can send at all.
 */
final class SmsMarketingOverviewWidget
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly UsageLimits $limits,
        private readonly ChannelGateway $gateway,
    ) {}

    /** @return array<string, int|string|bool|null> */
    public function __invoke(User $user): array
    {
        $campaigns = fn () => $this->records->constrain(Campaign::query(), $user, 'sms_marketing.campaigns.view');
        $next = $campaigns()->where('status', CampaignStatus::Scheduled->value)->where('scheduled_for', '>=', now())->min('scheduled_for');
        $availability = $this->gateway->availability(MarketingChannel::Sms);

        return [
            'sending' => $campaigns()->where('status', CampaignStatus::Sending->value)->count(),
            'scheduled' => $campaigns()->where('status', CampaignStatus::Scheduled->value)->count(),
            'onHold' => $campaigns()->where('status', CampaignStatus::Sending->value)->whereNotNull('hold_reason')->count(),
            'segmentsUsed' => $this->limits->used(RecipientStatusApplier::LIMIT),
            'segmentsLimit' => $this->limits->limit(RecipientStatusApplier::LIMIT),
            'channelAvailable' => $availability->isAvailable(),
            'channelTestDouble' => $availability->testDouble,
            'nextScheduledFor' => is_string($next) ? Carbon::parse($next)->toIso8601String() : null,
        ];
    }
}
