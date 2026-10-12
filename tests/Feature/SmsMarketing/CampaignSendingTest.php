<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Actions\CommunicationSettings;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Testing\InMemoryProvider;
use App\Modules\SmsMarketing\Actions\AudienceEstimator;
use App\Modules\SmsMarketing\Actions\CampaignManager;
use App\Modules\SmsMarketing\Actions\CampaignRunner;
use App\Modules\SmsMarketing\Actions\SmsSettings;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\Recipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSmsMarketing;

uses(InteractsWithSmsMarketing::class);

beforeEach(function () {
    $this->tenant = $this->smsTenant('sms-send');
    $this->connectFakeProvider($this->tenant, Channel::Sms);

    // The recipients of a campaign keyed by contact id: `status` or `status:reason`.
    $this->states = fn (string $campaignId): array => $this->inTenant($this->tenant, fn () => Recipient::query()
        ->where('campaign_id', $campaignId)->get()
        ->mapWithKeys(fn (Recipient $r) => [$r->contact_id => $r->status->value.($r->reason ? ':'.$r->reason : '')])->all());
});

it('sends a campaign to opted-in contacts and records consent-less contacts as blocked', function () {
    $yes = $this->smsContact($this->tenant, '+8801711000001');
    $also = $this->smsContact($this->tenant, '01811000002', true, ['first_name' => 'Karim']);
    $no = $this->smsContact($this->tenant, '+8801711000003', false);
    $group = $this->smsGroup($this->tenant, [$yes->id, $also->id, $no->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);
    $run = $this->runCampaigns($this->tenant);

    expect($run['materialized'])->toBe(1)->and($run['handed'])->toBe(3);

    $states = ($this->states)($campaign->id);
    expect($states[$yes->id])->toBe('sent')
        ->and($states[$also->id])->toBe('sent')
        ->and($states[$no->id])->toBe('blocked:no_consent');

    // The in-memory provider only saw the two opted-in numbers, each message ends with the opt-out line and link.
    expect(InMemoryProvider::$sent)->toHaveCount(2);
    $texts = array_map(fn ($payload) => $payload->body, InMemoryProvider::$sent);
    expect($texts[0])->toContain('Opt out: http')->and($texts[0])->toContain('/communication/unsubscribe/');
    expect(implode("\n", $texts))->toContain('Hello Rahim')->toContain('Hello Karim');

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $fresh = Campaign::query()->findOrFail($campaign->id);
        expect($fresh->status)->toBe(CampaignStatus::Completed)->and($fresh->completed_at)->not->toBeNull();
    });
});

it('personalizes with the fallback when a value is missing', function () {
    $contact = $this->smsContact($this->tenant, '+8801711000001', true, ['first_name' => '', 'last_name' => null]);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent[0]->body)->toContain('Hello friend,');
});

it('is idempotent: a second run, or a second materialization, sends nothing twice', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $this->runCampaigns($this->tenant);
    $this->inTenant($this->tenant, function () use ($campaign): void {
        app(CampaignRunner::class)->materialize(Campaign::query()->findOrFail($campaign->id));
    });
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(1);
    $this->inTenant($this->tenant, function () use ($campaign): void {
        expect(Recipient::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
            ->and(Message::query()->count())->toBe(1);
    });
});

it('puts a contact who is in two groups in the campaign once', function () {
    $contact = $this->smsContact($this->tenant);
    $one = $this->smsGroup($this->tenant, [$contact->id], 'One');
    $two = $this->smsGroup($this->tenant, [$contact->id], 'Two');
    $campaign = $this->smsCampaign($this->tenant, [$one->id, $two->id]);

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(1);
});

it('skips an invalid number and records the reason', function () {
    $bad = $this->smsContact($this->tenant, '12345');
    $good = $this->smsContact($this->tenant, '+8801711000009');
    $group = $this->smsGroup($this->tenant, [$bad->id, $good->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    $states = ($this->states)($campaign->id);
    expect($states[$bad->id])->toBe('skipped:invalid_number')->and($states[$good->id])->toBe('sent');
});

it('records opt-outs made after the audience was built as blocked, never sent', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);
    $this->launchNow($this->tenant, $campaign);

    // Materialize, then the contact opts out before the batch goes.
    $this->inTenant($this->tenant, function () use ($campaign, $contact): void {
        app(CampaignRunner::class)->materialize(Campaign::query()->findOrFail($campaign->id));
        app(ConsentManager::class)->record(
            Contact::query()->findOrFail($contact->id), Channel::Sms, ConsentStatus::OptedOut, 'verbal',
        );
    });
    $this->runCampaigns($this->tenant);

    expect(($this->states)($campaign->id)[$contact->id])->toBe('blocked:opted_out')
        ->and(InMemoryProvider::$sent)->toBeEmpty();
});

it('refuses to launch while no SMS provider is configured, and says why', function () {
    $other = $this->smsTenant('sms-nochannel');
    $contact = $this->smsContact($other);
    $group = $this->smsGroup($other, [$contact->id]);
    $campaign = $this->smsCampaign($other, [$group->id]);

    // Drafting and estimating work; launching does not.
    expect(fn () => $this->launchNow($other, $campaign))->toThrow(ValidationException::class);

    $this->inTenant($other, function () use ($campaign): void {
        expect(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Draft);
        $estimate = app(AudienceEstimator::class)->estimate(
            [app(Group::class)->newQuery()->firstOrFail()->id], 'Hi {{first_name}}',
        );
        expect($estimate['channelAvailable'])->toBeFalse()->and($estimate['contacts'])->toBe(1);
    });
});

it('stops at the hard daily cap and resumes the next day', function () {
    $ids = [];
    foreach (range(1, 5) as $n) {
        $ids[] = $this->smsContact($this->tenant, '+88017110001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT))->id;
    }
    $group = $this->smsGroup($this->tenant, $ids);
    $this->inTenant($this->tenant, fn () => app(SmsSettings::class)->update(['daily_cap' => 2, 'throttle_per_minute' => 100]));
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(2);
    $this->inTenant($this->tenant, function () use ($campaign): void {
        $fresh = Campaign::query()->findOrFail($campaign->id);
        expect($fresh->status)->toBe(CampaignStatus::Sending)->and($fresh->hold_reason)->toBe('daily_cap')
            ->and(Recipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')->count())->toBe(3);
    });

    // Tomorrow, in the workspace's day.
    $this->travelTo(CarbonImmutable::now()->addDay());
    $this->runCampaigns($this->tenant);
    $this->travelBack();

    expect(InMemoryProvider::$sent)->toHaveCount(4);
});

it('throttles one run to the per-minute rate', function () {
    $ids = [];
    foreach (range(1, 6) as $n) {
        $ids[] = $this->smsContact($this->tenant, '+88017110002'.str_pad((string) $n, 2, '0', STR_PAD_LEFT))->id;
    }
    $group = $this->smsGroup($this->tenant, $ids);
    $this->inTenant($this->tenant, fn () => app(SmsSettings::class)->update(['daily_cap' => 1000, 'throttle_per_minute' => 4]));
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);

    expect($this->runCampaigns($this->tenant)['handed'])->toBe(4)
        ->and($this->runCampaigns($this->tenant)['handed'])->toBe(2);
});

it('starts a scheduled campaign only when it is due', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->launch($campaign, CarbonImmutable::now()->addHours(2)));
    expect($this->runCampaigns($this->tenant)['activated'])->toBe(0)->and(InMemoryProvider::$sent)->toBeEmpty();

    $this->travelTo(CarbonImmutable::now()->addHours(3));
    $run = $this->runCampaigns($this->tenant);
    $this->travelBack();

    expect($run['activated'])->toBe(1)->and(InMemoryProvider::$sent)->toHaveCount(1);
});

it('rejects a schedule in the past and one too far ahead', function () {
    $group = $this->smsGroup($this->tenant, [$this->smsContact($this->tenant)->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    foreach ([CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addDays(120)] as $at) {
        expect(fn () => $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->launch($campaign, $at)))
            ->toThrow(ValidationException::class);
    }
});

it('cancels a running campaign: pending recipients are skipped and nothing more is sent', function () {
    $ids = [];
    foreach (range(1, 4) as $n) {
        $ids[] = $this->smsContact($this->tenant, '+88017110003'.str_pad((string) $n, 2, '0', STR_PAD_LEFT))->id;
    }
    $group = $this->smsGroup($this->tenant, $ids);
    $this->inTenant($this->tenant, fn () => app(SmsSettings::class)->update(['daily_cap' => 1000, 'throttle_per_minute' => 1]));
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->cancel(Campaign::query()->findOrFail($campaign->id)));
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(1);
    $this->inTenant($this->tenant, function () use ($campaign): void {
        expect(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Cancelled)
            ->and(Recipient::query()->where('campaign_id', $campaign->id)->where('reason', 'campaign_cancelled')->count())->toBe(3);
    });
});

it('refuses to launch to more contacts than the audience bound', function () {
    config(['sms_marketing.max_audience' => 1]);
    $group = $this->smsGroup($this->tenant, [
        $this->smsContact($this->tenant, '+8801711000001')->id,
        $this->smsContact($this->tenant, '+8801711000002')->id,
    ]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    expect(fn () => $this->launchNow($this->tenant, $campaign))->toThrow(ValidationException::class);
});

it('respects quiet hours through the platform: the message waits and nothing is sent', function () {
    Queue::fake();
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $this->inTenant($this->tenant, fn () => app(CommunicationSettings::class)->update([
        'quiet_hours_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00',
    ]));
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);
    $this->travelTo(CarbonImmutable::parse('2026-03-02 23:30', 'Asia/Dhaka'));

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $this->travelBack();

    $row = $this->inTenant($this->tenant, fn () => Recipient::query()->where('campaign_id', $campaign->id)->sole());
    expect($row->status)->toBe(RecipientStatus::Queued)
        ->and($row->due_at?->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-03 08:00')
        ->and(InMemoryProvider::$sent)->toBeEmpty();
});

it('holds the campaign, and fails nobody, when the channel is switched off while it runs', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);
    $this->launchNow($this->tenant, $campaign);

    $this->inTenant($this->tenant, fn () => ChannelConnection::query()->where('channel', Channel::Sms)->update(['is_enabled' => false]));
    $this->runCampaigns($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $fresh = Campaign::query()->findOrFail($campaign->id);
        $row = Recipient::query()->where('campaign_id', $campaign->id)->sole();

        expect($fresh->status)->toBe(CampaignStatus::Sending)
            ->and($fresh->hold_reason)->toBe('channel_unavailable')
            ->and($row->status)->toBe(RecipientStatus::Pending)
            ->and($row->attempts)->toBe(0);
    });
    expect(InMemoryProvider::$sent)->toBeEmpty();

    // Switched back on, the next run sends it and clears the hold.
    $this->inTenant($this->tenant, fn () => ChannelConnection::query()->where('channel', Channel::Sms)->update(['is_enabled' => true]));
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(1);
});

it('lets only one run work on a workspace at a time', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);
    $this->launchNow($this->tenant, $campaign);

    // Held inside one workspace context, like two overlapping workers of the same workspace.
    $overlapping = $this->inTenant($this->tenant, function (): array {
        $lock = Cache::lock('sms_marketing:runner', 60);
        $acquired = $lock->get();
        $run = app(CampaignRunner::class)->run();
        $lock->release();

        return [$acquired, $run];
    });

    expect($overlapping[0])->toBeTrue()
        ->and($overlapping[1])->toBe(['activated' => 0, 'materialized' => 0, 'handed' => 0])
        ->and(InMemoryProvider::$sent)->toBeEmpty();

    expect($this->runCampaigns($this->tenant)['handed'])->toBe(1);
});
