<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\SmsMarketing\Actions\CampaignStats;
use App\Modules\SmsMarketing\Actions\MessageComposer;
use App\Modules\SmsMarketing\Actions\SmsSettings;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\Recipient;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Models\Setting;
use App\Modules\SmsMarketing\Models\Template;

/** The shapes the SMS Marketing pages receive (camelCase, ids and scalars only). */
final class SmsPresenter
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly MessageComposer $composer,
        private readonly SmsSettings $settings,
    ) {}

    /**
     * @param  array<string, int>  $stats
     * @return array<string, mixed>
     */
    public function campaignRow(Campaign $campaign, array $stats): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'holdReason' => $campaign->hold_reason,
            'scheduledFor' => $campaign->scheduled_for?->toIso8601String(),
            'startedAt' => $campaign->started_at?->toIso8601String(),
            'completedAt' => $campaign->completed_at?->toIso8601String(),
            'estimatedRecipients' => $campaign->estimated_recipients,
            'estimatedSegments' => $campaign->estimated_segments,
            'stats' => $stats,
            'createdAt' => $campaign->created_at?->toIso8601String(),
            'owner' => $campaign->owner === null ? null : ['id' => $campaign->owner->id, 'name' => $campaign->owner->user->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function campaignDetail(Campaign $campaign, User $actor): array
    {
        $campaign->load(['owner.user:id,name', 'groupLinks.group:id,name', 'sender', 'template:id,name']);
        $stats = CampaignStats::for([$campaign->id])[$campaign->id];
        $can = fn (string $permission): bool => $this->records->allows($actor, $permission, $campaign);
        $status = $campaign->status;
        $permanent = (array) config('sms_marketing.permanent_failure_codes', []);
        $retryable = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::Failed->value)
            ->where('attempts', '<', (int) config('sms_marketing.max_attempts', 3))
            ->where(static fn ($query) => $query->whereNull('reason')->orWhereNotIn('reason', $permanent))
            ->count();

        return [
            ...$this->campaignRow($campaign, $stats),
            'body' => $campaign->body,
            'templateName' => $campaign->template?->name,
            'groups' => $campaign->groupLinks->map(static fn ($link): array => ['id' => $link->group_id, 'name' => $link->group->name])->values()->all(),
            'groupIds' => $campaign->groupLinks->pluck('group_id')->values()->all(),
            'templateId' => $campaign->template_id,
            'senderIdentityId' => $campaign->sender_identity_id,
            'sender' => $campaign->sender === null ? null : ['id' => $campaign->sender->id, 'label' => $campaign->sender->label, 'value' => $campaign->sender->value],
            'maxAttempts' => (int) config('sms_marketing.max_attempts', 3),
            'retryable' => $retryable,
            'abilities' => [
                'edit' => $status->isEditable() && $can('sms_marketing.campaigns.edit'),
                'delete' => in_array($status, [CampaignStatus::Draft, CampaignStatus::Cancelled], true) && $can('sms_marketing.campaigns.delete'),
                'launch' => $status === CampaignStatus::Draft && $can('sms_marketing.campaigns.launch'),
                'cancel' => $status->isOpen() && $can('sms_marketing.campaigns.launch'),
                'retry' => $retryable > 0 && in_array($status, [CampaignStatus::Sending, CampaignStatus::Completed], true) && $can('sms_marketing.campaigns.launch'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function group(Group $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'membersCount' => (int) ($group->members_count ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function template(Template $template): array
    {
        $count = $this->composer->preview($template->body, $template->purpose->value === 'marketing');

        return [
            'id' => $template->id,
            'name' => $template->name,
            'purpose' => $template->purpose->value,
            'body' => $template->body,
            'isActive' => $template->is_active,
            'segments' => $count->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function sender(SenderId $sender): array
    {
        return [
            'id' => $sender->id,
            'label' => $sender->label,
            'value' => $sender->value,
            'type' => $sender->type->value,
            'registrationStatus' => $sender->registration_status->value,
            'registrationReference' => $sender->registration_reference,
            'registrationNote' => $sender->registration_note,
        ];
    }

    /** @return array<string, mixed> */
    public function settings(Setting $settings): array
    {
        return [
            'dailyCap' => $settings->daily_cap,
            'throttlePerMinute' => $settings->throttle_per_minute,
            'optOutWording' => $settings->opt_out_wording,
            'optOutDefault' => SmsSettings::DEFAULT_OPT_OUT,
            'segmentCostAmount' => $settings->segment_cost_amount,
            'currency' => $settings->segment_cost_amount === null ? $this->settings->currency() : ($settings->currency ?? $this->settings->currency()),
            'defaultSenderId' => $settings->default_sender_id,
            'bounds' => [
                'dailyCap' => array_values((array) config('sms_marketing.bounds.daily_cap', [1, 100000])),
                'throttlePerMinute' => array_values((array) config('sms_marketing.bounds.throttle_per_minute', [1, 600])),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function recipient(Recipient $recipient): array
    {
        $permanent = (array) config('sms_marketing.permanent_failure_codes', []);

        return [
            'id' => $recipient->id,
            'contactId' => $recipient->contact_id,
            'name' => $recipient->contact?->fullName() ?? '',
            'phone' => $recipient->phone,
            'status' => $recipient->status->value,
            'reason' => $recipient->reason,
            'segments' => $recipient->segments,
            'encoding' => $recipient->encoding,
            'attempts' => $recipient->attempts,
            'sentAt' => $recipient->sent_at?->toIso8601String(),
            'deliveredAt' => $recipient->delivered_at?->toIso8601String(),
            'nextAttemptAt' => $recipient->next_attempt_at?->toIso8601String(),
            'retryable' => $recipient->status === RecipientStatus::Failed
                && $recipient->attempts < (int) config('sms_marketing.max_attempts', 3)
                && ! in_array($recipient->reason, $permanent, true),
        ];
    }
}
