<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Testing\InMemoryProvider;
use App\Modules\SmsMarketing\Automation\Actions\AddContactToGroupAction;
use App\Modules\SmsMarketing\Automation\Triggers\CampaignCompletedTrigger;
use App\Modules\SmsMarketing\Automation\Triggers\CampaignLaunchedTrigger;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Events\CampaignCompleted;
use App\Modules\SmsMarketing\Events\CampaignLaunched;
use App\Modules\SmsMarketing\Models\AutomationActionReceipt;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\GroupMember;
use App\Modules\SmsMarketing\Models\Recipient;
use Illuminate\Support\Facades\Event;
use Tests\Support\InteractsWithReports;
use Tests\Support\InteractsWithSmsMarketing;

uses(InteractsWithSmsMarketing::class, InteractsWithReports::class);

beforeEach(function () {
    $this->tenant = $this->smsTenant('sms-integration');
    $this->connectFakeProvider($this->tenant, Channel::Sms);
});

it('registers its triggers and its action with the automation platform', function () {
    $registry = app(AutomationRegistry::class);

    expect($registry->trigger('sms_marketing.campaign.launched')?->class ?? $registry->trigger('sms_marketing.campaign.launched'))->not->toBeNull()
        ->and($registry->trigger('sms_marketing.campaign.completed'))->not->toBeNull()
        ->and($registry->action('sms_marketing.group.add_contact'))->toBe(AddContactToGroupAction::class)
        ->and(CampaignLaunchedTrigger::event())->toBe(CampaignLaunched::class)
        ->and(CampaignCompletedTrigger::event())->toBe(CampaignCompleted::class);
});

it('runs the add-contact action once per idempotency key and never reaches another workspace', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant);
    $other = $this->smsTenant('sms-integration-other');
    $foreignContact = $this->smsContact($other);
    $action = app(AddContactToGroupAction::class);
    $input = ['group_id' => $group->id, 'contact_id' => $contact->id];

    $first = $this->inTenant($this->tenant, fn () => $action->execute($input, 'run-1:step-1'));
    $again = $this->inTenant($this->tenant, fn () => $action->execute($input, 'run-1:step-1'));
    $second = $this->inTenant($this->tenant, fn () => $action->execute($input, 'run-2:step-1'));

    expect($first)->toEqual(['group_id' => $group->id, 'contact_id' => $contact->id, 'added' => true])
        ->and($again)->toEqual($first)
        ->and($second['added'])->toBeFalse();

    $this->inTenant($this->tenant, function () use ($group): void {
        expect(GroupMember::query()->where('group_id', $group->id)->count())->toBe(1)
            ->and(AutomationActionReceipt::query()->count())->toBe(2);
    });

    expect(fn () => $this->inTenant($this->tenant, fn () => $action->execute(['group_id' => $group->id, 'contact_id' => $foreignContact->id], 'k-9')))
        ->toThrow(InvalidArgumentException::class);
});

it('announces a launch and a completion exactly once each', function () {
    Event::fake([CampaignLaunched::class, CampaignCompleted::class]);
    $group = $this->smsGroup($this->tenant, [$this->smsContact($this->tenant)->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);

    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $this->runCampaigns($this->tenant);

    Event::assertDispatchedTimes(CampaignLaunched::class, 1);
    Event::assertDispatchedTimes(CampaignCompleted::class, 1);
    Event::assertDispatched(CampaignCompleted::class, fn (CampaignCompleted $event) => $event->payload()['recipients'] === 1 && $event->payload()['sent'] === 1);
});

it('ignores status events of another module, and events about a recipient it does not own', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    $recipient = $this->inTenant($this->tenant, fn () => Recipient::query()->where('campaign_id', $campaign->id)->sole());
    expect($recipient->status)->toBe(RecipientStatus::Sent);

    $this->inTenant($this->tenant, function () use ($recipient, $campaign, $contact): void {
        event(new MarketingMessageStatusChanged([
            'message_id' => (string) $recipient->message_id,
            'module' => 'email_marketing',
            'campaign_id' => $campaign->id,
            'recipient_id' => $recipient->id,
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'state' => 'failed',
            'failure_code' => 'x',
        ]));
    });

    expect($this->inTenant($this->tenant, fn () => Recipient::query()->findOrFail($recipient->id)->status))->toBe(RecipientStatus::Sent);
});

it('puts a working one-click opt-out in every message and honours it in the next campaign', function () {
    $contact = $this->smsContact($this->tenant, '+8801711000001');
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $first = $this->smsCampaign($this->tenant, [$group->id], ['name' => 'First']);
    $this->launchNow($this->tenant, $first);
    $this->runCampaigns($this->tenant);

    preg_match('~https?://\S+/communication/unsubscribe/([A-Za-z0-9]{40})~', InMemoryProvider::$sent[0]->body, $match);
    expect($match)->not->toBeEmpty();

    // The recipient opens the link and confirms.
    $this->post($this->tenantUrl($this->tenant, '/communication/unsubscribe/'.$match[1]))->assertOk();

    $second = $this->smsCampaign($this->tenant, [$group->id], ['name' => 'Second']);
    $this->launchNow($this->tenant, $second);
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(1);
    $row = $this->inTenant($this->tenant, fn () => Recipient::query()->where('campaign_id', $second->id)->sole());
    expect($row->status)->toBe(RecipientStatus::Blocked)->and($row->reason)->toBeIn(['unsubscribed', 'opted_out']);
});

it('does not send a retried campaign step twice when the same attempt key is used again', function () {
    $group = $this->smsGroup($this->tenant, [$this->smsContact($this->tenant)->id]);
    $campaign = $this->smsCampaign($this->tenant, [$group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    // A crashed worker left the claim in place: put the row back as the reconciler does and run again.
    $this->inTenant($this->tenant, fn () => Recipient::query()->where('campaign_id', $campaign->id)->update([
        'status' => RecipientStatus::Pending, 'attempts' => 0, 'message_id' => null,
    ]));
    $this->inTenant($this->tenant, fn () => Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending]));
    $this->runCampaigns($this->tenant);

    // The platform replayed the original message for the same key: nothing was delivered a second time.
    expect(InMemoryProvider::$sent)->toHaveCount(1);
    $row = $this->inTenant($this->tenant, fn () => Recipient::query()->where('campaign_id', $campaign->id)->sole());
    expect($row->message_id)->not->toBeNull();
});

it('publishes campaign and per-day reports to Reports & Analytics, with the permission that guards them', function () {
    $tenant = $this->createTenant('sms-reports', ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);
    $this->entitle($tenant, 'reports', 'communication', 'sms_marketing');
    $this->connectFakeProvider($tenant, Channel::Sms);
    $group = $this->smsGroup($tenant, [$this->smsContact($tenant)->id, $this->smsContact($tenant, '+8801711000002', false)->id]);
    $campaign = $this->smsCampaign($tenant, [$group->id], ['name' => 'Report me']);
    $this->launchNow($tenant, $campaign);
    $this->runCampaigns($tenant);

    [$user] = $this->reportsMember($tenant, ['reports.reports.view', 'sms_marketing.analytics.view']);
    [$blind] = $this->reportsMember($tenant, ['reports.reports.view']);

    $rows = fn ($member, string $key) => $this->actingAsMember($tenant, $member)
        ->get($this->reportsUrl($tenant, '/run/'.$key))->assertOk()->viewData('page')['props']['result']['rows'];

    $campaigns = $rows($user, 'sms_marketing.campaigns');
    expect($campaigns)->toHaveCount(1)
        ->and($campaigns[0])->toMatchArray(['campaign' => 'Report me', 'status' => 'completed', 'recipients' => 2, 'accepted' => 1, 'blocked' => 1, 'delivered' => 0, 'segments' => 1]);

    $daily = $rows($user, 'sms_marketing.daily');
    expect($daily)->toHaveCount(1)->and($daily[0])->toMatchArray(['sent' => 1, 'blocked' => 1]);

    $this->actingAsMember($tenant, $blind)->get($this->reportsUrl($tenant, '/run/sms_marketing.campaigns'))->assertNotFound();
});
