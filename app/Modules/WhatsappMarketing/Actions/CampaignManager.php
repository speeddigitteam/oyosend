<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Jobs\SendCampaignChunk;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Text;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campaign lifecycle: draft, schedule or start, cancel. Sending itself is {@see CampaignSender}, in bounded
 * chunks on the `campaigns` queue. Consent is never decided here: every recipient goes through the communication
 * platform's gateway, which checks the opt-in and the suppression list again at send time.
 */
final class CampaignManager
{
    public function __construct(
        private readonly VariableResolver $variables,
        private readonly ChannelStatus $channel,
        private readonly AuditLogger $audit,
        private readonly ListManager $lists,
    ) {}

    /**
     * @param  array{name: string, template_id: string, list_id: string, variables?: array<array-key, mixed>, header_media_type?: string|null, header_media_id?: string|null, buttons?: array<array-key, mixed>, sender_number_id?: string|null}  $input
     */
    public function save(?Campaign $campaign, array $input, ?TenantMembership $owner = null): Campaign
    {
        if ($campaign !== null && ! $campaign->status->isEditable()) {
            throw ValidationException::withMessages(['campaign' => __('Only a draft campaign can be changed.')]);
        }

        $name = Text::clean($input['name'], 120, false);

        if (Campaign::query()->where('name', $name)->when($campaign !== null, fn ($query) => $query->whereKeyNot($campaign?->id))->exists()) {
            throw ValidationException::withMessages(['name' => __('A campaign with this name already exists.')]);
        }

        $template = Template::query()->find($input['template_id'])
            ?? throw ValidationException::withMessages(['template_id' => __('Choose a template.')]);

        if ($template->status !== TemplateStatus::Approved) {
            throw ValidationException::withMessages(['template_id' => __('Only approved templates can be sent.')]);
        }

        $list = ContactList::query()->find($input['list_id'])
            ?? throw ValidationException::withMessages(['list_id' => __('Choose a list.')]);

        $map = $this->variables->normalizeMap((array) ($input['variables'] ?? []), $template->variable_count);

        // ADR 0022: an optional fixed media header and dynamic button values for the template.
        $headerType = in_array($input['header_media_type'] ?? null, ['image', 'video', 'document'], true) ? $input['header_media_type'] : null;
        $headerId = $headerType !== null && is_string($input['header_media_id'] ?? null) && $input['header_media_id'] !== '' ? $input['header_media_id'] : null;
        $buttons = $this->buttonParams((array) ($input['buttons'] ?? []));
        $senderNumberId = is_string($input['sender_number_id'] ?? null) && $input['sender_number_id'] !== '' ? $input['sender_number_id'] : null;

        if ($headerType !== null && $headerId === null) {
            throw ValidationException::withMessages(['header_media_id' => __('Choose a media file for the header.')]);
        }

        return DB::transaction(function () use ($campaign, $name, $template, $list, $map, $headerType, $headerId, $buttons, $senderNumberId, $owner): Campaign {
            $campaign ??= new Campaign(['status' => CampaignStatus::Draft, 'total_recipients' => 0, 'created_by' => $owner?->id]);
            $campaign->fill([
                'name' => $name,
                'template_id' => $template->id,
                'list_id' => $list->id,
                'template_name' => $template->name,
                'template_language' => $template->language,
                'template_category' => $template->category->value,
                'template_body' => $template->body_text,
                'list_name' => $list->name,
                'variable_map' => $map,
                'header_media_type' => $headerType,
                'header_media_id' => $headerId,
                'button_params' => $buttons === [] ? null : $buttons,
                'sender_number_id' => $senderNumberId,
            ]);

            if (! $campaign->exists && $owner !== null) {
                $campaign->assignOwner($owner);
            }

            $campaign->save();

            return $campaign;
        });
    }

    /**
     * Start now (no time, or a time that has arrived) or schedule for later.
     *
     * @throws ValidationException
     */
    public function schedule(Campaign $campaign, ?CarbonInterface $at = null): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
            throw ValidationException::withMessages(['campaign' => __('This campaign cannot be scheduled any more.')]);
        }

        if (! $this->channel->isAvailable()) {
            throw ValidationException::withMessages(['campaign' => __('WhatsApp is not connected for this workspace yet.')]);
        }

        $template = $campaign->template_id === null ? null : Template::query()->find($campaign->template_id);

        if ($template === null || $template->status !== TemplateStatus::Approved) {
            throw ValidationException::withMessages(['campaign' => __('Only approved templates can be sent.')]);
        }

        $members = $campaign->list_id === null ? 0 : ListMember::query()->where('list_id', $campaign->list_id)->count();
        $max = max(1, (int) config('whatsapp_marketing.max_recipients', 10000));

        if ($members === 0) {
            throw ValidationException::withMessages(['campaign' => __('The list has no contacts.')]);
        }

        if ($members > $max) {
            throw ValidationException::withMessages(['campaign' => __('A campaign sends to at most :max contacts.', ['max' => $max])]);
        }

        $now = CarbonImmutable::now();
        $when = $at === null ? null : CarbonImmutable::instance($at);

        if ($when !== null && $when->isAfter($now->addMinutes(1))) {
            DB::transaction(function () use ($campaign, $when): void {
                $campaign->fill(['status' => CampaignStatus::Scheduled, 'scheduled_at' => $when])->save();
                $this->audit->record('whatsapp_marketing.campaign.scheduled', $campaign, ['scheduled_at' => $when->toIso8601String()]);
            });

            return $campaign;
        }

        return $this->start($campaign);
    }

    /** Begin sending: the first chunk job takes the recipient snapshot. Safe to call twice. */
    public function start(Campaign $campaign): Campaign
    {
        DB::transaction(function () use ($campaign): void {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
                $campaign->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $locked->fill(['status' => CampaignStatus::Sending, 'started_at' => CarbonImmutable::now()])->save();
            $this->audit->record('whatsapp_marketing.campaign.started', $locked);
            $campaign->setRawAttributes($locked->getAttributes(), true);

            SendCampaignChunk::dispatch($locked->id);
        });

        return $campaign;
    }

    /** Stop a scheduled or sending campaign. Recipients not yet handed to the outbox are skipped. */
    public function cancel(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Sending], true)) {
            throw ValidationException::withMessages(['campaign' => __('This campaign cannot be cancelled.')]);
        }

        DB::transaction(function () use ($campaign): void {
            $campaign->fill(['status' => CampaignStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now(), 'stop_reason' => 'cancelled'])->save();

            Recipient::query()
                ->where('campaign_id', $campaign->id)
                ->where('state', RecipientState::Pending->value)
                ->update(['state' => RecipientState::Skipped->value, 'reason' => 'cancelled']);

            $this->audit->record('whatsapp_marketing.campaign.cancelled', $campaign);
        });

        return $campaign;
    }

    public function delete(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::Draft) {
            throw ValidationException::withMessages(['campaign' => __('Only a draft campaign can be deleted.')]);
        }

        $campaign->delete();
    }

    /** Members a list would send to, for the form. */
    public function listSize(ContactList $list): int
    {
        return $this->lists->count($list);
    }

    /**
     * Normalise the form's button variables into the {@see WhatsappContent::template()} button-parameter shape.
     *
     * @param  array<array-key, mixed>  $buttons
     * @return list<array{sub_type: string, index: int, value: string}>
     */
    private function buttonParams(array $buttons): array
    {
        $params = [];

        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }

            $type = $button['type'] ?? null;
            $index = $button['index'] ?? null;
            $value = is_string($button['value'] ?? null) ? Text::clean($button['value'], 2000, false) : '';

            if (! in_array($type, ['url', 'quick_reply', 'copy_code'], true) || ! is_int($index) || $value === '') {
                throw ValidationException::withMessages(['buttons' => __('Each button variable needs a position and a value.')]);
            }

            $params[] = ['sub_type' => $type, 'index' => $index, 'value' => $value];
        }

        return $params;
    }
}
