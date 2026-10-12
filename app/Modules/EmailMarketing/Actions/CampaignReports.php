<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Figures for the reports page, the dashboard widget and the report providers. Every number is counted from this
 * app's own delivery rows and the status the platform reported; nothing is estimated. Rates are basis points
 * (10000 = 100%) and `null` when the denominator is zero ("not reported", never a silent zero).
 *
 * Definitions: `sent` = handed to the mailer (status sent or delivered); the click rate is unique clickers over
 * `sent`. Opens of the email are not measured (plain-text mail), `views` counts the web version.
 */
final class CampaignReports
{
    /** @var list<string> */
    private const array STATUSES = ['pending', 'held', 'sending', 'queued', 'sent', 'delivered', 'failed', 'bounced', 'blocked', 'skipped', 'cancelled'];

    /**
     * @param  Builder<Delivery>  $query
     * @return array<string, int|null>
     */
    public function figures(Builder $query): array
    {
        $byStatus = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $figures = ['planned' => 0];

        foreach (self::STATUSES as $status) {
            $figures[$status] = (int) ($byStatus[$status] ?? 0);
            $figures['planned'] += $figures[$status];
        }

        $figures['sentTotal'] = $figures['sent'] + $figures['delivered'];
        $figures['clickers'] = (clone $query)->whereNotNull('clicked_at')->count();
        $figures['clicks'] = (int) (clone $query)->sum('click_count');
        $figures['viewers'] = (clone $query)->whereNotNull('viewed_at')->count();
        $figures['clickRate'] = $figures['sentTotal'] > 0 ? intdiv($figures['clickers'] * 10000, $figures['sentTotal']) : null;
        $figures['viewRate'] = $figures['sentTotal'] > 0 ? intdiv($figures['viewers'] * 10000, $figures['sentTotal']) : null;
        $figures['bounceRate'] = $figures['sentTotal'] + $figures['bounced'] > 0
            ? intdiv($figures['bounced'] * 10000, $figures['sentTotal'] + $figures['bounced'])
            : null;

        return $figures;
    }

    /** @return array<string, mixed> */
    public function overview(CarbonInterface $from, CarbonInterface $to): array
    {
        $deliveries = Delivery::query()->whereBetween('created_at', [$from, $to]);

        $reasons = (clone $deliveries)
            ->whereIn('status', [DeliveryStatus::Blocked->value, DeliveryStatus::Skipped->value])
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn (Delivery $row): array => ['reason' => (string) ($row->reason ?? 'unknown'), 'count' => (int) $row->getAttribute('total')])
            ->all();

        return [
            'deliveries' => $this->figures($deliveries),
            'campaigns' => [
                'finished' => Campaign::query()->where('status', CampaignStatus::Sent->value)->whereBetween('finished_at', [$from, $to])->count(),
                'active' => Campaign::query()->whereIn('status', [CampaignStatus::Preparing->value, CampaignStatus::Sending->value, CampaignStatus::AwaitingWinner->value])->count(),
                'scheduled' => Campaign::query()->where('status', CampaignStatus::Scheduled->value)->count(),
            ],
            'subscribers' => $this->subscribers($from, $to),
            'reasons' => $reasons,
        ];
    }

    /** @return array<string, int> */
    public function subscribers(CarbonInterface $from, CarbonInterface $to): array
    {
        $byStatus = Subscription::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'subscribed' => (int) ($byStatus[SubscriptionStatus::Subscribed->value] ?? 0),
            'pending' => (int) ($byStatus[SubscriptionStatus::Pending->value] ?? 0),
            'unsubscribed' => (int) ($byStatus[SubscriptionStatus::Unsubscribed->value] ?? 0),
            'bounced' => (int) ($byStatus[SubscriptionStatus::Bounced->value] ?? 0),
            'complained' => (int) ($byStatus[SubscriptionStatus::Complained->value] ?? 0),
            'joined' => Subscription::query()->whereBetween('subscribed_at', [$from, $to])->count(),
            'left' => Subscription::query()->whereBetween('unsubscribed_at', [$from, $to])->count(),
        ];
    }

    /**
     * One campaign in detail: totals, per variant and why messages were not sent.
     *
     * @return array<string, mixed>
     */
    public function campaign(Campaign $campaign): array
    {
        $base = fn (): Builder => Delivery::query()->where('campaign_id', $campaign->id);

        $variants = [];

        if ($campaign->ab_enabled) {
            foreach (['a', 'b'] as $variant) {
                $variants[$variant] = $this->figures($base()->where('variant', $variant)->whereNotIn('status', [DeliveryStatus::Blocked->value, DeliveryStatus::Skipped->value]));
            }
        }

        $reasons = $base()
            ->whereIn('status', [DeliveryStatus::Blocked->value, DeliveryStatus::Skipped->value, DeliveryStatus::Failed->value, DeliveryStatus::Bounced->value])
            ->selectRaw('reason, status, count(*) as total')
            ->groupBy('reason', 'status')
            ->orderByDesc('total')
            ->get()
            ->map(fn (Delivery $row): array => [
                'reason' => (string) ($row->reason ?? 'unknown'),
                'status' => $row->status->value,
                'count' => (int) $row->getAttribute('total'),
            ])->all();

        return ['figures' => $this->figures($base()), 'variants' => $variants, 'reasons' => $reasons];
    }

    /**
     * Totals for the campaigns of one page of the list.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, array<string, int>> campaign id => status => count
     */
    public function statusCounts(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $out = [];

        $rows = Delivery::query()
            ->whereIn('campaign_id', $campaignIds)
            ->selectRaw('campaign_id, status, count(*) as total')
            ->groupBy('campaign_id', 'status')
            ->get();

        foreach ($rows as $row) {
            $out[(string) $row->campaign_id][$row->status->value] = (int) $row->getAttribute('total');
        }

        return $out;
    }
}
