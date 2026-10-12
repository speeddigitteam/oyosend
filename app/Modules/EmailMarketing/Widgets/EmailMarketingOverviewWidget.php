<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Widgets;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\EmailMarketing\Actions\CampaignReports;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Subscription;

/**
 * Dashboard widget, shown with `email_marketing.campaigns.view`. Campaign counts follow the viewer's own /
 * department / branch / all scope; the subscriber total only appears with `subscribers.view`.
 */
final class EmailMarketingOverviewWidget
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly CampaignReports $reports,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(User $user): array
    {
        $campaigns = fn () => $this->records->constrain(Campaign::query(), $user, 'email_marketing.campaigns.view');
        $last = $campaigns()->where('status', CampaignStatus::Sent->value)->orderByDesc('finished_at')->first();
        $figures = $last === null ? null : $this->reports->campaign($last)['figures'];

        return [
            'scheduled' => $campaigns()->where('status', CampaignStatus::Scheduled->value)->count(),
            'sending' => $campaigns()->whereIn('status', [CampaignStatus::Preparing->value, CampaignStatus::Sending->value, CampaignStatus::AwaitingWinner->value])->count(),
            'paused' => $campaigns()->where('status', CampaignStatus::Paused->value)->count(),
            'subscribers' => $this->access->allows($user, 'email_marketing.subscribers.view')
                ? Subscription::query()->where('status', SubscriptionStatus::Subscribed->value)->distinct()->count('contact_id')
                : null,
            'last' => $last === null || $figures === null ? null : [
                'id' => $last->id,
                'name' => $last->name,
                'finishedAt' => $last->finished_at?->toIso8601String(),
                'sent' => $figures['sentTotal'],
                'blocked' => $figures['blocked'],
                'clickRate' => $figures['clickRate'],
            ],
        ];
    }
}
