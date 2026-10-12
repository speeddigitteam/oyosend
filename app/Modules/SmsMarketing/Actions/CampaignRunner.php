<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Billing\UsageLimits;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\Data\AudienceReason;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\SendReceipt;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\TenantContext;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\CampaignGroup;
use App\Modules\SmsMarketing\Models\Recipient;
use App\Modules\SmsMarketing\Support\PhoneNumber;
use App\Modules\SmsMarketing\Support\SegmentCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Moves campaigns forward. One run (every minute, through the entitlement-gated scheduled runner, on the
 * `campaigns` queue) does three bounded things for the active workspace:
 *
 *  1. starts scheduled campaigns that are due;
 *  2. builds the recipient list of campaigns that have none yet (one row per contact, de-duplicated);
 *  3. hands up to `min(throttle per minute, daily cap left, batch ceiling)` pending recipients to the
 *     communication platform through {@see ChannelGateway}.
 *
 * Consent and suppression are decided at hand-over time by the contract; a contact without consent is
 * recorded as `blocked` and nothing is sent. The run holds a per-workspace lock, so two overlapping runs
 * never send the same recipient (every claim is also a compare-and-set, and the gateway key is idempotent).
 */
final class CampaignRunner
{
    private const string LOCK = 'sms_marketing:runner';

    /** Recipients taken from one campaign in one pass. */
    private const int PASS = 200;

    private bool $held = false;

    public function __construct(
        private readonly SmsSettings $settings,
        private readonly ChannelGateway $gateway,
        private readonly MarketingAudience $audience,
        private readonly UnsubscribeLinks $links,
        private readonly MessageComposer $composer,
        private readonly AudienceEstimator $estimator,
        private readonly UsageLimits $limits,
        private readonly RecipientStatusApplier $applier,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{activated: int, materialized: int, handed: int}
     */
    public function run(): array
    {
        $lock = Cache::lock(self::LOCK, 300);

        if (! $lock->get()) {
            return ['activated' => 0, 'materialized' => 0, 'handed' => 0];
        }

        try {
            return [
                'activated' => $this->activateDue(),
                'materialized' => $this->materializeAll(),
                'handed' => $this->dispatchBatch(),
            ];
        } finally {
            $lock->release();
        }
    }

    private function activateDue(): int
    {
        $now = CarbonImmutable::now();

        return Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_for', '<=', $now)
            ->update(['status' => CampaignStatus::Sending->value, 'started_at' => $now, 'updated_at' => $now]);
    }

    private function materializeAll(): int
    {
        $count = 0;

        $campaigns = Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->whereNull('materialized_at')
            ->orderBy('started_at')
            ->limit(5)
            ->get();

        foreach ($campaigns as $campaign) {
            $this->materialize($campaign);
            $count++;
        }

        return $count;
    }

    /**
     * One recipient row per contact of the campaign's groups. Safe to repeat: the unique key makes a second
     * pass add nothing.
     */
    public function materialize(Campaign $campaign): void
    {
        $groupIds = CampaignGroup::query()->where('campaign_id', $campaign->id)->pluck('group_id')->all();
        $contactIds = array_slice($this->estimator->contactIds($groupIds), 0, (int) config('sms_marketing.max_audience', 50000));
        $tenantId = $this->context->requireId();
        $now = CarbonImmutable::now();

        foreach (array_chunk($contactIds, 1000) as $chunk) {
            $rows = [];

            foreach (Contact::query()->whereIn('id', $chunk)->get(['id', 'phone']) as $contact) {
                $phone = PhoneNumber::normalize($contact->phone);
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'phone' => $phone,
                    'status' => $phone === null ? RecipientStatus::Skipped->value : RecipientStatus::Pending->value,
                    'reason' => $phone === null ? 'invalid_number' : null,
                    'segments' => 0,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                Recipient::query()->insertOrIgnore($rows);
            }
        }

        Campaign::query()->whereKey($campaign->id)->whereNull('materialized_at')->update(['materialized_at' => $now, 'updated_at' => $now]);
        $this->applier->settle($campaign->id);
    }

    private function dispatchBatch(): int
    {
        $campaigns = Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->whereNotNull('materialized_at')
            ->orderBy('started_at')
            ->get();

        if ($campaigns->isEmpty()) {
            return 0;
        }

        $settings = $this->settings->current();
        $remaining = max(0, $settings->daily_cap - $this->handedToday());
        $budget = min($remaining, $settings->throttle_per_minute, (int) config('sms_marketing.batch_ceiling', 500));

        if ($budget <= 0) {
            foreach ($campaigns as $campaign) {
                $this->hold($campaign, 'daily_cap');
            }

            return 0;
        }

        $availability = $this->gateway->availability(MarketingChannel::Sms);

        if (! $availability->isAvailable()) {
            foreach ($campaigns as $campaign) {
                $this->hold($campaign, 'channel_unavailable');
            }

            return 0;
        }

        $handed = 0;

        foreach ($campaigns as $campaign) {
            if ($budget <= 0) {
                break;
            }

            $this->held = false;

            $rows = Recipient::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', RecipientStatus::Pending->value)
                ->where(static fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', CarbonImmutable::now()))
                ->orderBy('id')
                ->limit(min($budget, self::PASS))
                ->get();

            if ($rows->isNotEmpty()) {
                $done = $this->sendBatch($campaign, $rows->all());
                $handed += $done;
                $budget -= $done;
            }

            if (! $this->wasHeld() && $campaign->hold_reason !== null) {
                Campaign::query()->whereKey($campaign->id)->update(['hold_reason' => null]);
            }

            $this->applier->settle($campaign->id);
        }

        return $handed;
    }

    /**
     * @param  list<Recipient>  $rows
     * @return int Recipients that left this run in a final or in-flight state.
     */
    private function sendBatch(Campaign $campaign, array $rows): int
    {
        $now = CarbonImmutable::now();
        $claimed = [];

        foreach ($rows as $row) {
            $won = Recipient::query()
                ->whereKey($row->id)
                ->where('status', RecipientStatus::Pending->value)
                ->update([
                    'status' => RecipientStatus::Queued->value,
                    'attempts' => $row->attempts + 1,
                    'segments' => 0,
                    'queued_at' => $now,
                    'next_attempt_at' => null,
                    'updated_at' => $now,
                ]);

            if ($won === 1) {
                $row->attempts++;
                $claimed[] = $row;
            }
        }

        if ($claimed === []) {
            return 0;
        }

        $contacts = Contact::query()
            ->with('company:id,name')
            ->whereIn('id', array_values(array_filter(array_map(static fn (Recipient $row): ?string => $row->contact_id, $claimed))))
            ->get()
            ->keyBy('id');
        $decisions = $this->audience->decideMany(array_keys($contacts->all()), MarketingChannel::Sms);
        $locale = $this->context->require()->locale;
        $handed = 0;
        $stopped = false;

        foreach ($claimed as $row) {
            if ($stopped) {
                $this->release($row, null, true);

                continue;
            }

            $contact = $row->contact_id === null ? null : $contacts->get($row->contact_id);

            if ($contact === null) {
                $this->finish($row, RecipientStatus::Skipped, 'unknown_contact');
                $handed++;

                continue;
            }

            $phone = PhoneNumber::normalize($contact->phone);

            if ($phone === null) {
                $this->finish($row, RecipientStatus::Skipped, 'invalid_number');
                $handed++;

                continue;
            }

            $decision = $decisions[$contact->id] ?? null;

            if ($decision !== null && $decision->reason === AudienceReason::ChannelUnavailable) {
                $this->hold($campaign, 'channel_unavailable');
                $this->release($row, null, true);
                $stopped = true;

                continue;
            }

            if ($decision === null || ! $decision->allowed) {
                $this->finish($row, RecipientStatus::Blocked, ($decision === null ? AudienceReason::NoConsent : $decision->reason)->value);
                $handed++;

                continue;
            }

            $url = $this->links->issue($contact->id, MarketingChannel::Sms, 'sms_marketing');

            if ($url === null) {
                $this->finish($row, RecipientStatus::Failed, 'opt_out_link_unavailable');
                $handed++;

                continue;
            }

            $text = $this->composer->compose($campaign->body, AudienceEstimator::values($contact), $url);
            $count = SegmentCounter::count($text);

            if ($count->segments > (int) config('sms_marketing.max_segments', 10)) {
                $this->finish($row, RecipientStatus::Skipped, 'too_long');
                $handed++;

                continue;
            }

            if (! $this->limits->allows(RecipientStatusApplier::LIMIT, $this->inFlightSegments() + $count->segments)) {
                $this->hold($campaign, 'limit_reached');
                $this->release($row, null, true);
                $stopped = true;

                continue;
            }

            // What this attempt will cost is stored before the hand-over, because a status event can arrive
            // (synchronously, with a sync queue) before send() returns.
            $row->forceFill(['phone' => $phone, 'segments' => $count->segments, 'encoding' => $count->encoding]);
            Recipient::query()->whereKey($row->id)->where('status', RecipientStatus::Queued->value)->update([
                'phone' => $phone,
                'segments' => $count->segments,
                'encoding' => $count->encoding,
            ]);

            $receipt = $this->gateway->send(new MarketingMessage(
                module: 'sms_marketing',
                idempotencyKey: 'c:'.$campaign->id.':r:'.$row->id.':'.$row->attempts,
                channel: MarketingChannel::Sms,
                contactId: $contact->id,
                subject: null,
                body: $text,
                campaignId: $campaign->id,
                recipientId: $row->id,
                locale: $locale,
            ));

            if ($this->handle($campaign, $row, $receipt)) {
                $handed++;
            } else {
                $stopped = true;
            }
        }

        return $handed;
    }

    /**
     * @return bool False when the run must stop (the recipient went back to pending and the campaign is on hold).
     */
    private function handle(Campaign $campaign, Recipient $row, SendReceipt $receipt): bool
    {

        if ($receipt->state === DeliveryState::Unavailable) {
            if (in_array($receipt->reason, ['unknown_contact', 'no_address'], true)) {
                $this->finish($row, RecipientStatus::Skipped, (string) $receipt->reason);

                return true;
            }

            // Nothing was recorded, so the same key may be used again: give the attempt back.
            $this->hold($campaign, 'channel_unavailable');
            $this->release($row, null, true);

            return false;
        }

        if ($receipt->state === DeliveryState::Failed && $receipt->reason === 'limit_reached') {
            // The platform's own plan limit: its message exists as failed, so the next try needs a new key.
            $this->hold($campaign, 'platform_limit');
            $this->release($row, CarbonImmutable::now()->addHour(), false);

            return false;
        }

        // Record the platform's message id; a status event may already have moved the row on (synchronous queue),
        // but not back to pending, so only rows that are still in flight or accepted take it.
        Recipient::query()
            ->whereKey($row->id)
            ->whereNull('message_id')
            ->whereIn('status', [RecipientStatus::Queued->value, RecipientStatus::Sent->value, RecipientStatus::Delivered->value])
            ->update(['message_id' => $receipt->messageId, 'due_at' => $receipt->dueAt, 'updated_at' => CarbonImmutable::now()]);

        match ($receipt->state) {
            DeliveryState::Queued, DeliveryState::Sending => null,
            default => $this->applier->apply($row->id, $receipt->state, $receipt->reason),
        };

        return true;
    }

    /** Put a claimed recipient back to pending. `$giveBack` also returns the attempt (nothing was recorded). */
    private function release(Recipient $row, ?CarbonImmutable $notBefore, bool $giveBack): void
    {
        Recipient::query()->whereKey($row->id)->where('status', RecipientStatus::Queued->value)->update([
            'status' => RecipientStatus::Pending->value,
            'attempts' => $giveBack ? max(0, $row->attempts - 1) : $row->attempts,
            'next_attempt_at' => $notBefore,
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function finish(Recipient $row, RecipientStatus $status, string $reason): void
    {
        Recipient::query()->whereKey($row->id)->where('status', RecipientStatus::Queued->value)->update([
            'status' => $status->value,
            'reason' => $reason,
            'failed_at' => $status === RecipientStatus::Failed ? CarbonImmutable::now() : null,
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /** Whether the current pass put its campaign on hold (a method, so a nested call's effect is not assumed away). */
    private function wasHeld(): bool
    {
        return $this->held;
    }

    private function hold(Campaign $campaign, string $reason): void
    {
        $this->held = true;

        if ($campaign->hold_reason !== $reason) {
            Campaign::query()->whereKey($campaign->id)->update(['hold_reason' => $reason, 'updated_at' => CarbonImmutable::now()]);
            $campaign->hold_reason = $reason;
        }
    }

    /**
     * Segments of messages handed to the platform that it has not accepted yet. They are not in the usage
     * meter, so they are counted against the plan's allowance before the next message goes.
     */
    private function inFlightSegments(): int
    {
        return (int) Recipient::query()->where('status', RecipientStatus::Queued->value)->sum('segments');
    }

    /** Messages handed to the platform today (workspace day): the figure the hard daily cap is measured against. */
    private function handedToday(): int
    {
        return Recipient::query()
            ->where('queued_at', '>=', $this->settings->dayStartUtc())
            ->where(static function ($query): void {
                $query->whereIn('status', [
                    RecipientStatus::Queued->value,
                    RecipientStatus::Sent->value,
                    RecipientStatus::Delivered->value,
                    RecipientStatus::Failed->value,
                ])->orWhere(static fn ($retry) => $retry->where('status', RecipientStatus::Pending->value)->where('attempts', '>', 0));
            })
            ->count();
    }
}
