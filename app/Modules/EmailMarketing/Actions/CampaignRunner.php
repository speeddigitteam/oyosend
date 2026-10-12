<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\MarketingChannel;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Events\CampaignSent;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\VariantSplitter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Moves campaigns forward, a bounded step at a time. It is called by the every-minute job on the `campaigns`
 * queue, never from a web request, and is safe to run twice at once (a cache lock, then atomic row claims and
 * idempotent gateway keys).
 *
 * One pass, per workspace:
 *  1. releases deliveries a dead worker left in `sending`;
 *  2. starts campaigns that are due;
 *  3. works out the audience of a preparing campaign in chunks, asking the platform's `MarketingAudience` about
 *     consent and suppression for every chunk (a "no" is recorded as a blocked delivery and never sent);
 *  4. hands out at most `send_per_minute` deliveries across all campaigns;
 *  5. finishes campaigns, decides A/B winners and releases the held remainder.
 */
final class CampaignRunner
{
    public function __construct(
        private readonly MarketingAudience $audience,
        private readonly DeliverySender $sender,
        private readonly AudienceBuilder $builder,
        private readonly EmailMarketingSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int Deliveries handed to the platform in this pass.
     */
    public function run(): int
    {
        $lock = Cache::lock('email_marketing.campaign_run', (int) config('email_marketing.run_lock_seconds', 300));

        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->pass();
        } finally {
            $lock->release();
        }
    }

    private function pass(): int
    {
        $this->releaseStuck();
        $this->startDue();

        $preparing = Campaign::query()->where('status', CampaignStatus::Preparing->value)->orderBy('id')->get();

        foreach ($preparing as $campaign) {
            $this->prepare($campaign);
        }

        $budget = $this->settings->sendPerMinute();
        $handed = 0;

        $sending = Campaign::query()->where('status', CampaignStatus::Sending->value)->orderBy('id')->get();

        foreach ($sending as $campaign) {
            if ($budget <= 0) {
                break;
            }

            $count = $this->sendChunk($campaign, $budget);
            $budget -= $count;
            $handed += $count;
        }

        foreach (Campaign::query()->whereIn('status', [CampaignStatus::Sending->value, CampaignStatus::AwaitingWinner->value])->orderBy('id')->get() as $campaign) {
            $this->settle($campaign);
        }

        return $handed;
    }

    /** The runner's capacity left over for sequences after campaigns took what they needed. */
    public function leftover(int $handed): int
    {
        return max(0, $this->settings->sendPerMinute() - $handed);
    }

    private function releaseStuck(): void
    {
        $cutoff = CarbonImmutable::now()->subMinutes((int) config('email_marketing.stuck_after_minutes', 15));

        Delivery::query()
            ->where('status', DeliveryStatus::Sending->value)
            ->where('updated_at', '<', $cutoff)
            ->update(['status' => DeliveryStatus::Pending->value, 'updated_at' => CarbonImmutable::now()]);
    }

    private function startDue(): void
    {
        $due = Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_for', '<=', CarbonImmutable::now())
            ->get();

        foreach ($due as $campaign) {
            $started = Campaign::query()
                ->whereKey($campaign->id)
                ->where('status', CampaignStatus::Scheduled->value)
                ->update(['status' => CampaignStatus::Preparing->value, 'started_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

            if ($started > 0) {
                $this->audit->record('email_marketing.campaign.started', $campaign, [], subjectLabel: $campaign->name);
            }
        }
    }

    /** Work out one chunk of the audience into deliveries. */
    private function prepare(Campaign $campaign): void
    {
        $segment = $campaign->segment_id === null ? null : Segment::query()->find($campaign->segment_id);
        $chunk = max(1, min(1000, (int) config('email_marketing.audience_chunk', 500)));

        $members = $this->builder->members($campaign->list_id, $segment)
            ->when($campaign->cursor !== null, fn ($query) => $query->where('id', '>', $campaign->cursor))
            ->orderBy('id')
            ->limit($chunk)
            ->get(['id', 'contact_id']);

        if ($members->isEmpty()) {
            $campaign->fill([
                'status' => $campaign->audience_total === 0 ? CampaignStatus::Sent : CampaignStatus::Sending,
                'prepared_at' => CarbonImmutable::now(),
                'finished_at' => $campaign->audience_total === 0 ? CarbonImmutable::now() : null,
                'cursor' => null,
            ])->save();

            if ($campaign->status === CampaignStatus::Sent) {
                event(CampaignSent::from($campaign, 0, 0, 0));
            }

            return;
        }

        $decisions = $this->audience->decideMany($members->pluck('contact_id')->unique()->values()->all(), MarketingChannel::Email);
        $created = 0;

        /** @var Subscription $member */
        foreach ($members as $member) {
            $decision = $decisions[$member->contact_id] ?? null;
            $allowed = $decision !== null && $decision->allowed;
            $test = ! $campaign->ab_enabled || VariantSplitter::inTestCohort($campaign->id, $member->contact_id, $campaign->ab_test_percent);
            $variant = $campaign->ab_enabled && $test ? VariantSplitter::variant($campaign->id, $member->contact_id) : 'a';

            $status = match (true) {
                ! $allowed => DeliveryStatus::Blocked,
                $test => DeliveryStatus::Pending,
                default => DeliveryStatus::Held,
            };

            try {
                Delivery::query()->create([
                    'campaign_id' => $campaign->id,
                    'contact_id' => $member->contact_id,
                    'subscription_id' => $member->id,
                    'send_key' => 'campaign:'.$campaign->id.':'.$member->contact_id,
                    'variant' => $variant,
                    'status' => $status,
                    'reason' => $allowed ? null : ($decision?->reason->value ?? 'channel_unavailable'),
                ]);
                $created++;
            } catch (UniqueConstraintViolationException) {
                // This chunk was already worked out by an earlier, interrupted pass.
            }
        }

        $campaign->fill([
            'cursor' => $members->last()->id,
            'audience_total' => $campaign->audience_total + $created,
        ]);

        // A short chunk is the last one: the audience is complete and sending can begin in this same pass.
        if ($members->count() < $chunk) {
            $campaign->fill(['status' => CampaignStatus::Sending, 'prepared_at' => CarbonImmutable::now(), 'cursor' => null]);
        }

        $campaign->save();
    }

    /** Hand out up to `$budget` deliveries of one campaign. */
    private function sendChunk(Campaign $campaign, int $budget): int
    {
        $deliveries = Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', DeliveryStatus::Pending->value)
            ->orderBy('id')
            ->limit($budget)
            ->get();

        $handed = 0;

        foreach ($deliveries as $delivery) {
            // Re-read the campaign state: a member may have paused or cancelled it while this pass ran.
            if (! $this->stillSending($campaign)) {
                break;
            }

            $result = $this->sender->send($delivery, $campaign);

            if ($result->paused()) {
                $campaign->fill(['status' => CampaignStatus::Paused, 'paused_reason' => $result->pause])->save();
                $this->audit->record('email_marketing.campaign.paused', $campaign, ['reason' => $result->pause], subjectLabel: $campaign->name);

                break;
            }

            if ($result->status !== null) {
                $handed++;
            }
        }

        return $handed;
    }

    private function stillSending(Campaign $campaign): bool
    {
        return Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)->exists();
    }

    /** Finish a campaign whose open deliveries are done, and run the A/B decision. */
    private function settle(Campaign $campaign): void
    {
        $campaign->refresh();

        if ($campaign->status === CampaignStatus::AwaitingWinner) {
            if ($campaign->ab_decide_at !== null && $campaign->ab_decide_at->lte(CarbonImmutable::now())) {
                $this->decideWinner($campaign);
            }

            return;
        }

        if ($campaign->status !== CampaignStatus::Sending || $campaign->prepared_at === null) {
            return;
        }

        $open = Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Sending->value])
            ->exists();

        if ($open) {
            return;
        }

        $held = Delivery::query()->where('campaign_id', $campaign->id)->where('status', DeliveryStatus::Held->value)->exists();

        if ($held && $campaign->ab_enabled && $campaign->ab_winner === null) {
            $campaign->fill([
                'status' => CampaignStatus::AwaitingWinner,
                'ab_decide_at' => CarbonImmutable::now()->addHours($campaign->ab_wait_hours),
            ])->save();

            return;
        }

        $this->finish($campaign);
    }

    private function finish(Campaign $campaign): void
    {
        $campaign->fill(['status' => CampaignStatus::Sent, 'finished_at' => CarbonImmutable::now()])->save();

        $counts = Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sent = (int) ($counts[DeliveryStatus::Sent->value] ?? 0) + (int) ($counts[DeliveryStatus::Delivered->value] ?? 0) + (int) ($counts[DeliveryStatus::Queued->value] ?? 0);

        $this->audit->record('email_marketing.campaign.finished', $campaign, ['audience' => $campaign->audience_total, 'sent' => $sent], subjectLabel: $campaign->name);
        event(CampaignSent::from($campaign, $sent, (int) ($counts[DeliveryStatus::Blocked->value] ?? 0), (int) ($counts[DeliveryStatus::Skipped->value] ?? 0)));
    }

    /**
     * Pick the subject that did better among the test cohort and release the held remainder with it.
     * Only what is measured counts: with nothing measured variant A stays, and the reason says so.
     */
    private function decideWinner(Campaign $campaign): void
    {
        [$winner, $reason] = $this->measure($campaign);

        Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', DeliveryStatus::Held->value)
            ->update(['status' => DeliveryStatus::Pending->value, 'variant' => $winner, 'updated_at' => CarbonImmutable::now()]);

        $campaign->fill([
            'status' => CampaignStatus::Sending,
            'ab_winner' => $winner,
            'ab_winner_reason' => $reason,
            'ab_decided_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit->record('email_marketing.campaign.winner_decided', $campaign, ['winner' => $winner, 'reason' => $reason], subjectLabel: $campaign->name);
    }

    /** @return array{0: string, 1: string} variant and reason */
    private function measure(Campaign $campaign): array
    {
        if ($campaign->ab_metric === 'clicks' && ! $campaign->track_clicks) {
            return ['a', 'not_measured'];
        }

        $column = $campaign->ab_metric === 'views' ? 'viewed_at' : 'clicked_at';

        $stats = [];

        foreach (['a', 'b'] as $variant) {
            $base = Delivery::query()
                ->where('campaign_id', $campaign->id)
                ->where('variant', $variant)
                ->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value, DeliveryStatus::Queued->value]);

            $total = (clone $base)->count();
            $hits = (clone $base)->whereNotNull($column)->count();
            $stats[$variant] = $total === 0 ? 0.0 : $hits / $total;
            $stats[$variant.'_hits'] = $hits;
        }

        if ($stats['a_hits'] === 0 && $stats['b_hits'] === 0) {
            return ['a', 'no_responses'];
        }

        if ($stats['a'] === $stats['b']) {
            return ['a', 'tie'];
        }

        return $stats['b'] > $stats['a'] ? ['b', $campaign->ab_metric] : ['a', $campaign->ab_metric];
    }
}
