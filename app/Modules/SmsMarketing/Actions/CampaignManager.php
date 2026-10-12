<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use App\Modules\SmsMarketing\Events\CampaignLaunched;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\CampaignGroup;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\Recipient;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Models\Template;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafting, launching, cancelling and retrying SMS campaigns.
 *
 * A campaign is a draft until `launch()`. Launching checks that the channel can send now, that the audience is
 * not too large and that the plan has room for the estimated segments; it never sends inline. The every-minute
 * runner ({@see CampaignRunner}) builds the recipient list and sends in bounded batches.
 */
final class CampaignManager
{
    /** Furthest ahead a campaign can be scheduled, in days. */
    public const int MAX_SCHEDULE_DAYS = 90;

    public function __construct(
        private readonly MessageComposer $composer,
        private readonly AudienceEstimator $estimator,
        private readonly ChannelGateway $gateway,
        private readonly UsageLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, template_id?: string|null, body?: string|null, sender_identity_id?: string|null}  $attributes
     * @param  list<string>  $groupIds
     */
    public function create(array $attributes, array $groupIds, ?TenantMembership $owner = null): Campaign
    {
        return $this->save(new Campaign(['status' => CampaignStatus::Draft]), $attributes, $groupIds, $owner);
    }

    /**
     * @param  array{name: string, template_id?: string|null, body?: string|null, sender_identity_id?: string|null}  $attributes
     * @param  list<string>  $groupIds
     */
    public function update(Campaign $campaign, array $attributes, array $groupIds): Campaign
    {
        if (! $campaign->status->isEditable()) {
            throw ValidationException::withMessages(['campaign' => __('Only a draft campaign can be edited.')]);
        }

        return $this->save($campaign, $attributes, $groupIds, null);
    }

    public function delete(Campaign $campaign): void
    {
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['campaign' => __('Cancel a scheduled or running campaign before deleting it. A completed campaign is kept for the reports.')]);
        }

        DB::transaction(static fn () => $campaign->delete());
    }

    /**
     * Start the campaign now (`$at` null) or at a future time.
     *
     * @throws ValidationException
     */
    public function launch(Campaign $campaign, ?CarbonImmutable $at, ?TenantMembership $by = null): Campaign
    {
        return DB::transaction(function () use ($campaign, $at, $by): Campaign {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($locked->status !== CampaignStatus::Draft) {
                throw ValidationException::withMessages(['campaign' => __('This campaign was already launched.')]);
            }

            $now = CarbonImmutable::now();

            if ($at !== null && ($at->lessThan($now->addMinute()) || $at->greaterThan($now->addDays(self::MAX_SCHEDULE_DAYS)))) {
                throw ValidationException::withMessages(['scheduled_for' => __('Choose a time between one minute and :days days from now.', ['days' => self::MAX_SCHEDULE_DAYS])]);
            }

            $availability = $this->gateway->availability(MarketingChannel::Sms);

            if (! $availability->isAvailable()) {
                throw ValidationException::withMessages(['campaign' => __('SMS cannot be sent from this workspace yet (:reason). Connect an SMS provider in Communication settings first.', [
                    'reason' => $this->reasonText($availability->reason),
                ])]);
            }

            $groupIds = CampaignGroup::query()->where('campaign_id', $locked->id)->pluck('group_id')->all();

            if ($groupIds === []) {
                throw ValidationException::withMessages(['groups' => __('Choose at least one group.')]);
            }

            $this->composer->assertValid($locked->body, TemplatePurpose::Marketing);

            $estimate = $this->estimator->estimate($groupIds, $locked->body);

            if ($estimate['tooLarge']) {
                throw ValidationException::withMessages(['groups' => __('The chosen groups reach more than :max contacts. Split the campaign.', ['max' => (int) config('sms_marketing.max_audience', 50000)])]);
            }

            if ($estimate['contacts'] === 0) {
                throw ValidationException::withMessages(['groups' => __('The chosen groups have no contacts.')]);
            }

            $needed = $estimate['segments'];

            if ($needed > 0) {
                $this->limits->ensure(RecipientStatusApplier::LIMIT, $needed, 'campaign');
            }

            $locked->fill([
                'status' => $at === null ? CampaignStatus::Sending : CampaignStatus::Scheduled,
                'scheduled_for' => $at,
                'started_at' => $at === null ? $now : null,
                'estimated_recipients' => $estimate['sendable'] ?? $estimate['validNumbers'],
                'estimated_segments' => $needed,
                'launched_by' => $by?->id,
                'hold_reason' => null,
            ])->save();

            $this->audit->record('sms_marketing.campaign.launched', $locked, [
                'scheduled_for' => $at?->toIso8601String(),
                'estimated_segments' => $needed,
            ]);
            event(CampaignLaunched::from($locked));

            return $locked;
        });
    }

    public function cancel(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['campaign' => __('Only a scheduled or running campaign can be cancelled.')]);
            }

            $now = CarbonImmutable::now();
            $locked->fill(['status' => CampaignStatus::Cancelled, 'cancelled_at' => $now, 'hold_reason' => null])->save();

            // Messages already handed to the communication platform are not recalled; nothing more is handed over.
            $skipped = Recipient::query()
                ->where('campaign_id', $locked->id)
                ->where('status', RecipientStatus::Pending->value)
                ->update(['status' => RecipientStatus::Skipped->value, 'reason' => 'campaign_cancelled', 'next_attempt_at' => null, 'updated_at' => $now]);

            $this->audit->record('sms_marketing.campaign.cancelled', $locked, ['skipped' => $skipped]);

            return $locked;
        });
    }

    /**
     * Put failed recipients back in the queue. Bounded: a recipient is attempted at most
     * `sms_marketing.max_attempts` times in all, and a number or provider that refuses the message is not retried.
     *
     * @return int Recipients put back.
     */
    public function retryFailed(Campaign $campaign): int
    {
        return DB::transaction(function () use ($campaign): int {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (! in_array($locked->status, [CampaignStatus::Sending, CampaignStatus::Completed], true)) {
                throw ValidationException::withMessages(['campaign' => __('Only a running or completed campaign can retry failed messages.')]);
            }

            $permanent = (array) config('sms_marketing.permanent_failure_codes', []);
            $count = Recipient::query()
                ->where('campaign_id', $locked->id)
                ->where('status', RecipientStatus::Failed->value)
                ->where('attempts', '<', (int) config('sms_marketing.max_attempts', 3))
                ->where(static fn ($query) => $query->whereNull('reason')->orWhereNotIn('reason', $permanent))
                ->update([
                    'status' => RecipientStatus::Pending->value,
                    'next_attempt_at' => CarbonImmutable::now(),
                    'message_id' => null,
                    'failed_at' => null,
                    'updated_at' => CarbonImmutable::now(),
                ]);

            if ($count > 0 && $locked->status === CampaignStatus::Completed) {
                $locked->fill(['status' => CampaignStatus::Sending, 'completed_at' => null])->save();
            }

            if ($count > 0) {
                $this->audit->record('sms_marketing.campaign.retried', $locked, ['recipients' => $count]);
            }

            return $count;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $groupIds
     */
    private function save(Campaign $campaign, array $attributes, array $groupIds, ?TenantMembership $owner): Campaign
    {
        $templateId = $attributes['template_id'] ?? null;
        $template = null;

        if ($templateId !== null && $templateId !== '') {
            $template = Template::query()->find($templateId);

            if ($template === null || ! $template->is_active) {
                throw ValidationException::withMessages(['template_id' => __('Choose an active template.')]);
            }

            if ($template->purpose !== TemplatePurpose::Marketing) {
                throw ValidationException::withMessages(['template_id' => __('A transactional template cannot be used in a campaign.')]);
            }
        }

        $body = trim((string) ($template !== null && trim((string) ($attributes['body'] ?? '')) === '' ? $template->body : ($attributes['body'] ?? '')));
        $this->composer->assertValid($body, TemplatePurpose::Marketing);

        $senderId = $attributes['sender_identity_id'] ?? null;

        if ($senderId !== null && $senderId !== '' && ! SenderId::query()->whereKey($senderId)->exists()) {
            throw ValidationException::withMessages(['sender_identity_id' => __('Choose one of the workspace sender ids.')]);
        }

        $groups = Group::query()->whereIn('id', array_values(array_unique($groupIds)))->pluck('id')->all();

        if (count($groups) !== count(array_unique($groupIds))) {
            throw ValidationException::withMessages(['groups' => __('Choose groups of this workspace.')]);
        }

        try {
            return DB::transaction(function () use ($campaign, $attributes, $template, $body, $senderId, $groups, $owner): Campaign {
                $campaign->fill([
                    'name' => trim((string) $attributes['name']),
                    'template_id' => $template?->id,
                    'body' => $body,
                    'sender_identity_id' => $senderId === '' ? null : $senderId,
                ]);

                if ($owner !== null && ! $campaign->exists) {
                    $campaign->assignOwner($owner);
                }

                $campaign->save();
                $campaign->groupLinks()->whereNotIn('group_id', $groups)->delete();
                $existing = $campaign->groupLinks()->pluck('group_id')->all();

                foreach (array_diff($groups, $existing) as $groupId) {
                    CampaignGroup::query()->create(['campaign_id' => $campaign->id, 'group_id' => $groupId]);
                }

                return $campaign;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('A campaign with this name already exists.')]);
        }
    }

    private function reasonText(?string $reason): string
    {
        return match ($reason) {
            'module_unavailable' => __('the Communication app is not available'),
            'channel_disabled' => __('the SMS channel is switched off'),
            'provider_not_configured' => __('no SMS provider is configured'),
            default => __('not configured'),
        };
    }
}
