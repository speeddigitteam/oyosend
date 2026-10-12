<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Billing\UsageLimits;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\MarketingChannel;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\Recipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Delivery and usage figures from this app's own records.
 *
 * Delivered counts only what a provider receipt said; a provider that sends no receipts leaves messages at
 * `sent`, and the delivery rate is then null ("not reported"), never zero. The credit balance is shown only
 * when the provider adapter reports it; the platform contract has no balance call, so it is always
 * "not reported" today.
 */
final class SmsAnalytics
{
    public function __construct(
        private readonly SmsSettings $settings,
        private readonly UsageLimits $limits,
        private readonly ChannelGateway $gateway,
    ) {}

    /**
     * @return array{
     *     totals: array{handed: int, sent: int, delivered: int, failed: int, blocked: int, skipped: int, pending: int, segments: int},
     *     deliveryRate: float|null,
     *     daily: list<array{date: string, sent: int, delivered: int, failed: int, blocked: int, segments: int}>
     * }
     */
    public function overview(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Recipient::query()
            ->whereBetween('queued_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(segments), 0) as segments')
            ->groupBy('status')
            ->get();

        $totals = ['handed' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0, 'pending' => 0, 'segments' => 0];

        foreach ($rows as $row) {
            $status = $row->status;
            $count = (int) $row->getAttribute('total');

            $key = $status === RecipientStatus::Queued ? 'pending' : $status->value;
            $totals[$key] += $count;

            if (in_array($status, [RecipientStatus::Sent, RecipientStatus::Delivered, RecipientStatus::Queued, RecipientStatus::Failed], true)) {
                $totals['handed'] += $count;
            }

            if (in_array($status, [RecipientStatus::Sent, RecipientStatus::Delivered], true)) {
                $totals['segments'] += (int) $row->getAttribute('segments');
            }
        }

        $accepted = $totals['sent'] + $totals['delivered'];

        return [
            'totals' => $totals,
            // Only meaningful when at least one receipt exists in the period.
            'deliveryRate' => $totals['delivered'] > 0 && $accepted > 0 ? round($totals['delivered'] / $accepted * 100, 1) : null,
            'daily' => $this->daily($from, $to),
        ];
    }

    /**
     * @return array{channel: array{state: string, reason: string|null, testDouble: bool}, plan: array{limit: int|null, used: int, remaining: int|null}, contacts: array{limit: int|null, used: int}, credit: array{reported: bool, balance: int|null}, dailyCap: array{cap: int, used: int}}
     */
    public function standing(): array
    {
        $availability = $this->gateway->availability(MarketingChannel::Sms);
        $settings = $this->settings->current();
        $today = Recipient::query()
            ->where('queued_at', '>=', $this->settings->dayStartUtc())
            ->whereIn('status', [RecipientStatus::Queued->value, RecipientStatus::Sent->value, RecipientStatus::Delivered->value, RecipientStatus::Failed->value])
            ->count();

        return [
            'channel' => [
                'state' => $availability->state->value,
                'reason' => $availability->reason,
                'testDouble' => $availability->testDouble,
            ],
            'plan' => [
                'limit' => $this->limits->limit(RecipientStatusApplier::LIMIT),
                'used' => $this->limits->used(RecipientStatusApplier::LIMIT),
                'remaining' => $this->limits->remaining(RecipientStatusApplier::LIMIT),
            ],
            'contacts' => [
                'limit' => $this->limits->limit(GroupManager::LIMIT_CONTACTS),
                'used' => $this->limits->used(GroupManager::LIMIT_CONTACTS),
            ],
            // The provider adapter contract has no balance call: never a number.
            'credit' => ['reported' => false, 'balance' => null],
            'dailyCap' => ['cap' => $settings->daily_cap, 'used' => $today],
        ];
    }

    /**
     * Per-campaign figures for the most recent campaigns.
     *
     * @return list<array{id: string, name: string, status: string, stats: array<string, int>}>
     */
    public function campaigns(int $limit = 10): array
    {
        $campaigns = Campaign::query()->latest()->limit($limit)->get(['id', 'name', 'status']);
        $stats = CampaignStats::for($campaigns->pluck('id')->all());

        return $campaigns->map(static fn (Campaign $campaign): array => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'stats' => $stats[$campaign->id] ?? CampaignStats::empty(),
        ])->all();
    }

    /**
     * @return list<array{date: string, sent: int, delivered: int, failed: int, blocked: int, segments: int}>
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // A fixed offset (from the workspace timezone, `+06:00` in Dhaka): MySQL needs no timezone tables for it.
        $offset = $to->setTimezone($this->settings->timezone())->format('P');
        $day = "DATE(CONVERT_TZ(queued_at, '+00:00', '".$offset."'))";

        $rows = Recipient::query()
            ->whereBetween('queued_at', [$from, $to])
            ->selectRaw($day.' as day, status, COUNT(*) as total, COALESCE(SUM(segments), 0) as segments')
            ->groupBy(DB::raw($day), 'status')
            ->orderBy('day')
            ->get();

        $days = [];

        foreach ($rows as $row) {
            $day = (string) $row->getAttribute('day');
            $days[$day] ??= ['date' => $day, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'blocked' => 0, 'segments' => 0];
            $status = $row->status;
            $count = (int) $row->getAttribute('total');

            if ($status === RecipientStatus::Sent) {
                $days[$day]['sent'] += $count;
                $days[$day]['segments'] += (int) $row->getAttribute('segments');
            } elseif ($status === RecipientStatus::Delivered) {
                $days[$day]['delivered'] += $count;
                $days[$day]['segments'] += (int) $row->getAttribute('segments');
            } elseif ($status === RecipientStatus::Failed) {
                $days[$day]['failed'] += $count;
            } elseif ($status === RecipientStatus::Blocked) {
                $days[$day]['blocked'] += $count;
            }
        }

        return array_values($days);
    }
}
