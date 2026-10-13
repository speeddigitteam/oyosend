<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\MarketingChannel;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Message as OutboxMessage;
use App\Modules\WhatsappMarketing\Actions\CampaignManager;
use App\Modules\WhatsappMarketing\Actions\CampaignSender;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Jobs\RunCampaigns;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Recipient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-campaign');
    $this->waConnectCloud($this->tenant);
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
    $this->template = $this->waTemplate($this->tenant, ['name' => 'spring_offer']);

    // Three opted-in contacts, one without consent, one whose number is not international.
    $this->optedIn = [];

    foreach ([1, 2, 3] as $n) {
        $contact = $this->waContact($this->tenant, ['first_name' => "Customer{$n}", 'phone' => '+880171100000'.$n]);
        $this->waOptIn($this->tenant, $contact);
        $this->optedIn[] = $contact;
    }

    $this->noConsent = $this->waContact($this->tenant, ['first_name' => 'Nina', 'phone' => '+8801711000009']);
    $this->localNumber = $this->waContact($this->tenant, ['first_name' => 'Lal', 'phone' => '0212345678']);
    $this->list = $this->waList($this->tenant, [...$this->optedIn, $this->noConsent, $this->localNumber]);

    $this->payload = fn (array $overrides = []) => [
        'name' => 'Spring '.bin2hex(random_bytes(3)),
        'template_id' => $this->template->id,
        'list_id' => $this->list->id,
        'variables' => [
            1 => ['type' => 'field', 'field' => 'contact.first_name'],
            2 => ['type' => 'text', 'value' => 'Acme'],
        ],
        ...$overrides,
    ];
    $this->create = fn (array $overrides = []) => $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, '/campaigns'), ($this->payload)($overrides));
});

it('creates a draft with a template, a list and a variable mapping from allow-listed fields', function () {
    ($this->create)(['name' => 'Spring launch'])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        $campaign = Campaign::query()->where('name', 'Spring launch')->firstOrFail();

        expect($campaign->status)->toBe(CampaignStatus::Draft)
            ->and($campaign->template_name)->toBe('spring_offer')
            ->and($campaign->variable_map)->toBe([
                1 => ['type' => 'field', 'field' => 'contact.first_name'],
                2 => ['type' => 'text', 'value' => 'Acme'],
            ])
            ->and($campaign->owner_id)->toBe($this->membership->id);
    });
});

it('rejects a mapping to a field outside the allow-list, or a missing variable', function () {
    ($this->create)(['variables' => [
        1 => ['type' => 'field', 'field' => 'contact.email'],
        2 => ['type' => 'text', 'value' => 'x'],
    ]])->assertSessionHasErrors();

    ($this->create)(['variables' => [1 => ['type' => 'field', 'field' => 'contact.first_name']]])->assertSessionHasErrors('variables.2');

    // An expression is not a field and is not evaluated.
    ($this->create)(['variables' => [
        1 => ['type' => 'field', 'field' => '{{contact.first_name}} | upper'],
        2 => ['type' => 'text', 'value' => 'x'],
    ]])->assertSessionHasErrors();

    $this->inTenant($this->tenant, fn () => expect(Campaign::query()->count())->toBe(0));
});

it('refuses a template that is not approved', function () {
    $pending = $this->waTemplate($this->tenant, ['status' => 'pending']);

    ($this->create)(['template_id' => $pending->id])->assertSessionHasErrors('template_id');
});

it('sends only to opted-in contacts with a valid number and records who was blocked or skipped', function () {
    ($this->create)(['name' => 'Go'])->assertSessionHasNoErrors();
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->firstOrFail());

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"))
        ->assertSessionHasNoErrors();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request['template']['components'][0]['parameters'][1]['text'] === 'Acme');

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $campaign->refresh();
        $states = Recipient::query()->where('campaign_id', $campaign->id)->pluck('state', 'contact_id')->map(fn ($state) => $state->value)->all();

        expect($campaign->status)->toBe(CampaignStatus::Completed)
            ->and($campaign->total_recipients)->toBe(5)
            ->and($states[$this->optedIn[0]->id])->toBe('sent')
            ->and($states[$this->noConsent->id])->toBe('blocked')
            ->and($states[$this->localNumber->id])->toBe('skipped')
            ->and(Recipient::query()->where('state', RecipientState::Blocked->value)->value('reason'))->toBe('no_consent')
            ->and(Recipient::query()->where('state', RecipientState::Skipped->value)->value('reason'))->toBe('invalid_phone')
            // Marketing messages in the outbox only for people who opted in.
            ->and(OutboxMessage::query()->count())->toBe(3);
    });
});

it('hands recipients to the outbox in bounded chunks', function () {
    config(['whatsapp_marketing.chunk_size' => 2]);
    ($this->create)(['name' => 'Chunked'])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        $campaign = Campaign::query()->firstOrFail();
        $campaign->forceFill(['status' => CampaignStatus::Sending, 'started_at' => now()])->save();
        $sender = app(CampaignSender::class);
        $handedOver = fn (): int => Recipient::query()->where('campaign_id', $campaign->id)->whereNotIn('state', ['pending', 'blocked', 'skipped'])->count();

        expect($sender->run($campaign->id))->toBeTrue()
            ->and($handedOver())->toBe(2);

        expect($sender->run($campaign->id))->toBeTrue()
            ->and($handedOver())->toBe(3);

        // Nothing pending is left: the next run completes the campaign.
        expect($sender->run($campaign->id))->toBeFalse()
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
    });
});

it('never sends a person twice when a chunk is repeated', function () {
    ($this->create)(['name' => 'Twice'])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        $campaign = Campaign::query()->firstOrFail();
        app(CampaignManager::class)->schedule($campaign);
        expect($campaign->fresh()->status)->toBe(CampaignStatus::Completed);

        // A crash after the hand-over but before the recipient row was updated: the row is pending again.
        Recipient::query()->where('campaign_id', $campaign->id)->where('state', 'sent')->update(['state' => 'pending']);
        Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending->value]);

        app(CampaignSender::class)->run($campaign->id);
    });

    Http::assertSentCount(3);
    $this->inTenant($this->tenant, fn () => expect(OutboxMessage::query()->count())->toBe(3));
});

it('lets the platform re-check consent at send time', function () {
    ($this->create)(['name' => 'Late opt-out'])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        $campaign = Campaign::query()->firstOrFail();
        $campaign->forceFill(['status' => CampaignStatus::Sending, 'started_at' => now()])->save();
        $sender = app(CampaignSender::class);
        $sender->snapshot($campaign);

        // Between the snapshot and the send one contact withdraws consent.
        app(ConsentManager::class)->record($this->optedIn[0], Channel::Whatsapp, ConsentStatus::OptedOut, 'other');

        while ($sender->run($campaign->id)) {
            // keep going
        }

        $withdrawn = Recipient::query()->where('contact_id', $this->optedIn[0]->id)->firstOrFail();

        expect($withdrawn->state)->toBe(RecipientState::Blocked)
            ->and($withdrawn->reason)->toBe('opted_out');
    });

    Http::assertSentCount(2);
});

it('schedules for later and starts a due campaign from the scheduled job', function () {
    ($this->create)(['name' => 'Later'])->assertSessionHasNoErrors();
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->firstOrFail());

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"), ['scheduled_at' => now()->addDay()->toIso8601String()])
        ->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled));
    Http::assertNothingSent();

    // Not due yet: the job leaves it.
    $this->inTenant($this->tenant, fn () => app()->call([new RunCampaigns, 'handle']));
    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled));

    $this->travel(25)->hours();
    $this->inTenant($this->tenant, fn () => app()->call([new RunCampaigns, 'handle']));

    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Completed));
    Http::assertSentCount(3);
});

it('cancels a scheduled campaign and sends nothing', function () {
    ($this->create)(['name' => 'Cancelled'])->assertSessionHasNoErrors();
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->firstOrFail());

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"), ['scheduled_at' => now()->addDay()->toIso8601String()]);
    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/cancel"))
        ->assertSessionHasNoErrors();

    $this->travel(2)->days();
    $this->inTenant($this->tenant, fn () => app()->call([new RunCampaigns, 'handle']));

    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Cancelled));
    Http::assertNothingSent();
});

it('does not start when the WhatsApp channel is not connected', function () {
    $this->inTenant($this->tenant, fn () => ChannelConnection::query()->delete());
    ($this->create)(['name' => 'Offline'])->assertSessionHasNoErrors();
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->firstOrFail());

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"))
        ->assertSessionHasErrors('campaign');

    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft));
    Http::assertNothingSent();
});

it('stops a running campaign when the channel goes away and keeps the rest pending', function () {
    ($this->create)(['name' => 'Interrupted'])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        $campaign = Campaign::query()->firstOrFail();
        $campaign->forceFill(['status' => CampaignStatus::Sending, 'started_at' => now()])->save();
        ChannelConnection::query()->update(['is_enabled' => false]);

        expect(app(CampaignSender::class)->run($campaign->id))->toBeFalse();

        $campaign->refresh();

        expect($campaign->status)->toBe(CampaignStatus::Failed)
            ->and($campaign->stop_reason)->toBe('channel_unavailable');
    });

    Http::assertNothingSent();
});

it('refuses an empty list and a campaign that is not a draft', function () {
    $empty = $this->waList($this->tenant, [], 'Empty');
    ($this->create)(['name' => 'No one', 'list_id' => $empty->id])->assertSessionHasNoErrors();
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->where('name', 'No one')->firstOrFail());

    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"))
        ->assertSessionHasErrors('campaign');

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $campaign->forceFill(['status' => CampaignStatus::Completed])->save();

        expect(fn () => app(CampaignManager::class)->save($campaign, ($this->payload)(['name' => 'Edited'])))->toThrow(ValidationException::class)
            ->and(fn () => app(CampaignManager::class)->delete($campaign))->toThrow(ValidationException::class);
    });
});

it('reads a recipient\'s progress from the platform status events', function () {
    ($this->create)(['name' => 'Progress'])->assertSessionHasNoErrors();
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->firstOrFail());
    $this->actingAsMember($this->tenant, $this->user)->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"));

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $recipient = Recipient::query()->where('campaign_id', $campaign->id)->where('contact_id', $this->optedIn[0]->id)->firstOrFail();
        $wamid = OutboxMessage::query()->where('contact_id', $this->optedIn[0]->id)->value('provider_message_id');

        expect($recipient->state)->toBe(RecipientState::Sent)->and($wamid)->toBeString();

        $this->waStatus($this->tenant, (string) $wamid, 'delivered')->assertOk();
        expect($recipient->fresh()->state)->toBe(RecipientState::Delivered);

        $this->waStatus($this->tenant, (string) $wamid, 'read')->assertOk();
        expect($recipient->fresh()->state)->toBe(RecipientState::Read)->and($recipient->fresh()->read_at)->not->toBeNull();
    });
});

it('puts campaign messages in the contact\'s conversation', function () {
    ($this->create)(['name' => 'Thread'])->assertSessionHasNoErrors();
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->firstOrFail());
    $this->actingAsMember($this->tenant, $this->user)->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"));

    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->where('contact_id', $this->optedIn[0]->id)->firstOrFail();

        expect($conversation->messages()->where('sender_type', 'campaign')->count())->toBe(1)
            ->and($conversation->messages()->firstOrFail()->body)->toBe('Hi Customer1, your offer from Acme is ready.');
    });
});

it('reports the channel as available only through the platform contract', function () {
    $this->inTenant($this->tenant, function (): void {
        $availability = app(ChannelGateway::class)->availability(MarketingChannel::Whatsapp);

        expect($availability->isAvailable())->toBeTrue()->and($availability->testDouble)->toBeFalse();
    });
});
