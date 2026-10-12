<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageMeter;
use App\Core\Communication\Data\DeliveryState;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Events\CampaignCompleted;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\Recipient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one place a recipient changes state after its message was handed to the communication platform.
 *
 * Status events, the synchronous receipt of `send()` and the hourly reconciliation all come here, so a repeated
 * or out-of-order answer is harmless: every change is a compare-and-set on the current status, a delivered
 * message never goes back to sent, and usage is recorded once per attempt (idempotency key).
 */
final class RecipientStatusApplier
{
    /** Plan limit: segments of messages the provider accepted, in the billing period. */
    public const string LIMIT = 'sms_marketing.segments_per_month';

    public function __construct(
        private readonly UsageMeter $meter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool Whether the recipient changed.
     */
    public function apply(string $recipientId, DeliveryState $state, ?string $code = null, ?CarbonInterface $at = null): bool
    {
        $recipient = Recipient::query()->find($recipientId);

        if ($recipient === null) {
            return false;
        }

        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $changed = match ($state) {
            DeliveryState::Sent => $this->accepted($recipient, RecipientStatus::Sent, $now),
            DeliveryState::Delivered => $this->accepted($recipient, RecipientStatus::Delivered, $now),
            DeliveryState::Failed => $this->failed($recipient, $code ?? 'failed', $now, false),
            DeliveryState::Bounced => $this->failed($recipient, $code ?? 'bounced', $now, true),
            DeliveryState::Blocked => $this->move($recipient, [RecipientStatus::Pending, RecipientStatus::Queued], [
                'status' => RecipientStatus::Blocked->value,
                'reason' => $code ?? 'suppressed',
                'next_attempt_at' => null,
            ]),
            DeliveryState::Cancelled => $this->move($recipient, [RecipientStatus::Queued], [
                'status' => RecipientStatus::Skipped->value,
                'reason' => 'cancelled',
            ]),
            DeliveryState::Queued, DeliveryState::Sending, DeliveryState::Unavailable => false,
        };

        if ($changed) {
            $this->settle($recipient->campaign_id);
        }

        return $changed;
    }

    /**
     * Mark the campaign completed when nothing is waiting or in flight any more.
     */
    public function settle(string $campaignId): void
    {
        $campaign = Campaign::query()->find($campaignId);

        if ($campaign === null || $campaign->status !== CampaignStatus::Sending || $campaign->materialized_at === null) {
            return;
        }

        $open = Recipient::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Queued->value])
            ->exists();

        if ($open) {
            return;
        }

        $done = Campaign::query()
            ->whereKey($campaignId)
            ->where('status', CampaignStatus::Sending->value)
            ->update(['status' => CampaignStatus::Completed->value, 'completed_at' => CarbonImmutable::now(), 'hold_reason' => null]);

        if ($done === 1) {
            $fresh = $campaign->refresh();
            $stats = CampaignStats::for([$campaignId])[$campaignId];
            $this->audit->record('sms_marketing.campaign.completed', $fresh, ['recipients' => $stats['recipients'], 'accepted' => $stats['accepted']]);
            event(CampaignCompleted::from($fresh, $stats));
        }
    }

    private function accepted(Recipient $recipient, RecipientStatus $status, CarbonImmutable $now): bool
    {
        $from = $status === RecipientStatus::Delivered
            ? [RecipientStatus::Pending, RecipientStatus::Queued, RecipientStatus::Sent]
            : [RecipientStatus::Pending, RecipientStatus::Queued];

        $attributes = ['status' => $status->value, 'reason' => null, 'failed_at' => null, 'next_attempt_at' => null, 'due_at' => null];

        if ($status === RecipientStatus::Delivered) {
            $attributes['delivered_at'] = $now;
            $attributes['sent_at'] = $recipient->sent_at ?? $now;
        } else {
            $attributes['sent_at'] = $now;
        }

        $changed = $this->move($recipient, $from, $attributes);

        if ($changed && $recipient->segments > 0) {
            // Counted once per attempt, when the provider has taken the message.
            $this->meter->record(self::LIMIT, $recipient->segments, 'r:'.$recipient->id.':'.$recipient->attempts, [
                'campaign_id' => $recipient->campaign_id,
                'recipient_id' => $recipient->id,
            ], $now);
        }

        return $changed;
    }

    private function failed(Recipient $recipient, string $code, CarbonImmutable $now, bool $permanent): bool
    {
        $retry = ! $permanent
            && $recipient->attempts < (int) config('sms_marketing.max_attempts', 3)
            && ! in_array($code, (array) config('sms_marketing.permanent_failure_codes', []), true);

        if ($retry) {
            $steps = array_values((array) config('sms_marketing.retry_backoff_minutes', [15, 60]));
            $wait = (int) ($steps[min(max($recipient->attempts - 1, 0), count($steps) - 1)] ?? 60);

            $moved = $this->move($recipient, [RecipientStatus::Queued, RecipientStatus::Sent], [
                'status' => RecipientStatus::Pending->value,
                'reason' => $code,
                'next_attempt_at' => $now->addMinutes($wait),
                'message_id' => null,
            ]);

            if ($moved) {
                // The failure may arrive after the campaign looked finished: it has work again.
                Campaign::query()
                    ->whereKey($recipient->campaign_id)
                    ->where('status', CampaignStatus::Completed->value)
                    ->update(['status' => CampaignStatus::Sending->value, 'completed_at' => null, 'updated_at' => $now]);
            }

            return $moved;
        }

        return $this->move($recipient, [RecipientStatus::Pending, RecipientStatus::Queued, RecipientStatus::Sent], [
            'status' => RecipientStatus::Failed->value,
            'reason' => $code,
            'failed_at' => $now,
            'next_attempt_at' => null,
        ]);
    }

    /**
     * @param  list<RecipientStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function move(Recipient $recipient, array $from, array $attributes): bool
    {
        $moved = Recipient::query()
            ->whereKey($recipient->id)
            ->whereIn('status', array_map(static fn (RecipientStatus $status): string => $status->value, $from))
            ->update([...$attributes, 'updated_at' => CarbonImmutable::now()]);

        return $moved === 1;
    }
}
