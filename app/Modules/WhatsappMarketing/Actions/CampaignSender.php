<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\Whatsapp\MediaReference;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Events\CampaignCompleted;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends a campaign in bounded chunks. One call does at most `config('whatsapp_marketing.chunk_size')`
 * recipients (default 50); the chunk job re-dispatches itself until none are pending.
 *
 *  - The recipient snapshot is taken once, in pages of 500, with the platform's batch audience decision per page.
 *  - Every message carries the idempotency key `campaign:{id}:recipient:{id}`: a retried chunk, a duplicated
 *    job or a crashed worker never sends a person twice.
 *  - The platform re-checks consent and suppression when it sends. This class never decides them.
 */
final class CampaignSender
{
    private const array CHANNEL_DOWN = ['module_unavailable', 'channel_disabled', 'provider_not_configured'];

    public function __construct(
        private readonly MarketingAudience $audience,
        private readonly Messenger $messenger,
        private readonly ConversationManager $conversations,
        private readonly VariableResolver $variables,
        private readonly SettingsManager $settings,
        private readonly StatusProgress $progress,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly ChannelStatus $channel,
    ) {}

    /**
     * Take the snapshot if needed, then send one chunk.
     *
     * @return bool Whether more work remains (the caller re-dispatches).
     */
    public function run(string $campaignId): bool
    {
        $campaign = Campaign::query()->find($campaignId);

        if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
            return false;
        }

        // Without a connected channel nobody can be reached: stop before anything is decided or recorded.
        if (! $this->channel->isAvailable()) {
            $this->stop($campaign, CampaignStatus::Failed, 'channel_unavailable');

            return false;
        }

        if ($campaign->snapshot_at === null) {
            $this->snapshot($campaign);
        }

        $template = $campaign->template_id === null ? null : Template::query()->find($campaign->template_id);

        if ($template === null || $template->status !== TemplateStatus::Approved) {
            $this->stop($campaign, CampaignStatus::Failed, 'template_unavailable');

            return false;
        }

        $size = max(1, (int) config('whatsapp_marketing.chunk_size', 50));
        $pending = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('state', RecipientState::Pending->value)
            ->orderBy('id')
            ->limit($size)
            ->get();

        if ($pending->isEmpty()) {
            $this->finish($campaign);

            return false;
        }

        $contacts = Contact::query()->with('company:id,name')->whereIn('id', $pending->pluck('contact_id')->all())->get()->keyBy('id');
        $workspace = $this->settings->current()->business_name ?? $this->context->require()->name;

        // ADR 0022: one fixed media header and fixed button values for every recipient of this campaign.
        $header = $campaign->header_media_type !== null && $campaign->header_media_id !== null && WhatsappMediaType::tryFrom($campaign->header_media_type) !== null
            ? new MediaReference(WhatsappMediaType::from($campaign->header_media_type), mediaId: $campaign->header_media_id)
            : null;
        $buttons = $campaign->button_params ?? [];

        foreach ($pending as $recipient) {
            // Cancelled while this chunk ran.
            if ($this->cancelled($campaign)) {
                return false;
            }

            $contact = $contacts[$recipient->contact_id] ?? null;
            $at = CarbonImmutable::now();

            if ($contact === null) {
                $this->progress->recipient($recipient, RecipientState::Skipped, 'unknown_contact', $at);

                continue;
            }

            $conversation = $this->conversations->forContact($contact);

            if ($conversation === null) {
                $this->progress->recipient($recipient, RecipientState::Skipped, 'invalid_phone', $at);

                continue;
            }

            $parameters = $this->variables->parameters(
                $campaign->variable_map,
                $this->variables->valuesFor($contact, $contact->company, $workspace),
            );

            try {
                $outcome = $this->messenger->sendTemplate($conversation, $template, $parameters, null, SenderType::Campaign, $campaign, $recipient, headerMedia: $header, buttonParams: $buttons, senderNumberId: $campaign->sender_number_id);
            } catch (ValidationException) {
                // The plan's conversation limit, or a contact whose number no longer matches.
                $this->progress->recipient($recipient, RecipientState::Failed, 'send_refused', $at);

                continue;
            }

            $receipt = $outcome->receipt;
            $recipient->forceFill(['message_id' => $outcome->message->id, 'communication_message_id' => $receipt->messageId])->save();

            if ($receipt->state === DeliveryState::Unavailable && in_array((string) $receipt->reason, self::CHANNEL_DOWN, true)) {
                // Nothing can be sent until the connection is back: stop here, keep the rest pending.
                $this->stop($campaign, CampaignStatus::Failed, 'channel_unavailable');

                return false;
            }

            [$state, $reason] = match ($receipt->state) {
                DeliveryState::Blocked => [RecipientState::Blocked, $receipt->reason],
                DeliveryState::Failed, DeliveryState::Bounced, DeliveryState::Cancelled => [RecipientState::Failed, $receipt->reason],
                DeliveryState::Unavailable => [RecipientState::Skipped, $receipt->reason],
                DeliveryState::Sent => [RecipientState::Sent, null],
                DeliveryState::Delivered => [RecipientState::Delivered, null],
                default => [RecipientState::Queued, null],
            };

            $this->progress->recipient($recipient, $state, $reason, $at);
        }

        return true;
    }

    /** Freeze who the campaign goes to: the list as it is now, each person with the platform's decision. */
    public function snapshot(Campaign $campaign): void
    {
        $max = max(1, (int) config('whatsapp_marketing.max_recipients', 10000));
        $page = max(50, (int) config('whatsapp_marketing.snapshot_page', 500));
        $tenantId = $this->context->requireId();
        $after = '';
        $total = 0;

        while ($total < $max) {
            $members = ListMember::query()
                ->where('list_id', $campaign->list_id)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($page)
                ->get(['id', 'contact_id']);

            if ($members->isEmpty()) {
                break;
            }

            $after = (string) $members->last()->id;
            $ids = $members->pluck('contact_id')->all();
            $contacts = Contact::query()->whereIn('id', $ids)->get(['id', 'phone'])->keyBy('id');
            $decisions = $this->audience->decideMany($ids, MarketingChannel::Whatsapp);
            $now = now();
            $rows = [];

            foreach ($ids as $contactId) {
                $contact = $contacts[$contactId] ?? null;

                if ($contact === null || $total >= $max) {
                    continue;
                }

                $decision = $decisions[$contactId] ?? null;
                [$state, $reason] = match (true) {
                    Phone::e164($contact->phone) === null => [RecipientState::Skipped, 'invalid_phone'],
                    $decision === null || ! $decision->allowed => [RecipientState::Blocked, $decision === null ? 'no_consent' : $decision->reason->value],
                    default => [RecipientState::Pending, null],
                };

                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contactId,
                    'state' => $state->value,
                    'reason' => $reason,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $total++;
            }

            Recipient::query()->insertOrIgnore($rows);
        }

        $campaign->forceFill([
            'snapshot_at' => CarbonImmutable::now(),
            'total_recipients' => Recipient::query()->where('campaign_id', $campaign->id)->count(),
        ])->save();
    }

    private function finish(Campaign $campaign): void
    {
        $counts = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->toBase()->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->map(static fn (mixed $count): int => (int) $count);

        DB::transaction(function () use ($campaign, $counts): void {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== CampaignStatus::Sending) {
                return;
            }

            $locked->fill(['status' => CampaignStatus::Completed, 'completed_at' => CarbonImmutable::now()])->save();
            $this->audit->record('whatsapp_marketing.campaign.completed', $locked);

            event(new CampaignCompleted([
                'campaign_id' => $locked->id,
                'total' => (int) $counts->sum(),
                'queued' => (int) ($counts['queued'] ?? 0) + (int) ($counts['sent'] ?? 0) + (int) ($counts['delivered'] ?? 0) + (int) ($counts['read'] ?? 0),
                'blocked' => (int) ($counts['blocked'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'skipped' => (int) ($counts['skipped'] ?? 0),
            ]));
        });
    }

    private function stop(Campaign $campaign, CampaignStatus $status, string $reason): void
    {
        DB::transaction(function () use ($campaign, $status, $reason): void {
            $campaign->fill(['status' => $status, 'stop_reason' => $reason, 'completed_at' => CarbonImmutable::now()])->save();
            $this->audit->record('whatsapp_marketing.campaign.stopped', $campaign, ['reason' => $reason]);
        });
    }

    private function cancelled(Campaign $campaign): bool
    {
        return ! Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)->exists();
    }
}
