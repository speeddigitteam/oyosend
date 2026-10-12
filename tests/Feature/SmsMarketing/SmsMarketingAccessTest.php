<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Models\Campaign;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithSmsMarketing;

uses(InteractsWithSmsMarketing::class);

beforeEach(function () {
    $this->tenant = $this->smsTenant('sms-access');
    $this->connectFakeProvider($this->tenant);
    $this->contact = $this->smsContact($this->tenant);
    $this->group = $this->smsGroup($this->tenant, [$this->contact->id]);
});

it('requires a signed-in member of the workspace', function () {
    $this->get($this->smsUrl($this->tenant, '/campaigns'))->assertRedirect();
});

it('grants nothing by default: a member without the permission is refused', function () {
    [$user] = $this->smsMember($this->tenant, ['communication.notifications.view']);
    $this->actingAsMember($this->tenant, $user);

    foreach (['/campaigns', '/groups', '/templates', '/sender-ids', '/analytics', '/settings'] as $path) {
        $this->get($this->smsUrl($this->tenant, $path))->assertForbidden();
    }

    $this->post($this->smsUrl($this->tenant, '/campaigns'), ['name' => 'X', 'body' => 'Hi', 'group_ids' => [$this->group->id]])->assertForbidden();
});

it('renders each page with the props it declares for a permitted member', function () {
    [$user] = $this->smsMember($this->tenant, [
        'sms_marketing.campaigns.view', 'sms_marketing.campaigns.create', 'sms_marketing.groups.view', 'sms_marketing.groups.manage',
        'sms_marketing.templates.view', 'sms_marketing.senders.view', 'sms_marketing.analytics.view', 'sms_marketing.settings.manage',
        'core.contacts.view',
    ]);
    $this->actingAsMember($this->tenant, $user);
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);

    $this->get($this->smsUrl($this->tenant, '/campaigns'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Modules/SmsMarketing/Pages/Campaigns/Index')
        ->has('campaigns.data', 1)
        ->where('standing.channel.state', 'available')
        ->where('standing.credit', ['reported' => false, 'balance' => null])
        ->where('canCreate', true));

    $this->get($this->smsUrl($this->tenant, '/campaigns/create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Modules/SmsMarketing/Pages/Campaigns/Form')
        ->where('campaign', null)
        ->has('form.groups', 1)
        ->where('form.limits.maxSegments', 10)
        ->where('canEstimate', true));

    $this->get($this->smsUrl($this->tenant, "/campaigns/{$campaign->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Modules/SmsMarketing/Pages/Campaigns/Show')
        ->where('campaign.status', 'draft')
        ->where('campaign.abilities.launch', false)
        ->has('recipients.data', 0)
        ->has('sample.segments'));

    $this->get($this->smsUrl($this->tenant, "/groups/{$this->group->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Modules/SmsMarketing/Pages/Groups/Show')
        ->has('members.data', 1)
        ->where('canManage', true)
        ->has('importInfo.maxRows'));

    $this->get($this->smsUrl($this->tenant, '/groups'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Modules/SmsMarketing/Pages/Groups/Index')->has('groups.data', 1));
    $this->get($this->smsUrl($this->tenant, '/templates'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Modules/SmsMarketing/Pages/Templates/Index')->has('variables.marketing', 4));
    $this->get($this->smsUrl($this->tenant, '/sender-ids'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Modules/SmsMarketing/Pages/Senders/Index'));
    $this->get($this->smsUrl($this->tenant, '/analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Modules/SmsMarketing/Pages/Analytics/Index')
        ->where('report.deliveryRate', null)
        ->where('standing.credit.reported', false));
    $this->get($this->smsUrl($this->tenant, '/settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Modules/SmsMarketing/Pages/Settings/Index')
        ->where('settings.dailyCap', 1000));
});

it('keeps a view-only member from creating, launching or managing anything', function () {
    [$user] = $this->smsMember($this->tenant, ['sms_marketing.campaigns.view', 'sms_marketing.groups.view', 'sms_marketing.templates.view', 'sms_marketing.senders.view']);
    $this->actingAsMember($this->tenant, $user);
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);

    $this->get($this->smsUrl($this->tenant, "/campaigns/{$campaign->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('campaign.abilities', ['edit' => false, 'delete' => false, 'launch' => false, 'cancel' => false, 'retry' => false]));

    $this->get($this->smsUrl($this->tenant, '/campaigns/create'))->assertForbidden();
    $this->post($this->smsUrl($this->tenant, "/campaigns/{$campaign->id}/launch"))->assertForbidden();
    $this->post($this->smsUrl($this->tenant, "/campaigns/{$campaign->id}/cancel"))->assertForbidden();
    $this->post($this->smsUrl($this->tenant, '/groups'), ['name' => 'X'])->assertForbidden();
    $this->post($this->smsUrl($this->tenant, '/templates'), ['name' => 'X', 'purpose' => 'marketing', 'body' => 'Hi', 'is_active' => true])->assertForbidden();
    $this->post($this->smsUrl($this->tenant, '/sender-ids'), ['label' => 'B', 'value' => 'ACME', 'type' => 'masking', 'registration_status' => 'not_registered'])->assertForbidden();
    $this->put($this->smsUrl($this->tenant, '/settings'), ['daily_cap' => 10, 'throttle_per_minute' => 5])->assertForbidden();
});

it('narrows campaigns to the viewer\'s own when the grant is own-scoped', function () {
    [$user, $membership] = $this->smsMember($this->tenant, ['sms_marketing.campaigns.view', 'sms_marketing.campaigns.create'], [
        'sms_marketing.campaigns.view' => AccessScope::Own->value,
    ]);
    $this->actingAsMember($this->tenant, $user);

    $mine = $this->smsCampaign($this->tenant, [$this->group->id], ['name' => 'Mine']);
    $this->inTenant($this->tenant, fn () => Campaign::query()->whereKey($mine->id)->first()->assignOwner($membership)->save());
    $theirs = $this->smsCampaign($this->tenant, [$this->group->id], ['name' => 'Theirs']);
    $this->inTenant($this->tenant, fn () => Campaign::query()->whereKey($theirs->id)->first()->keepUnowned()->assignOwner(null)->save());

    $this->get($this->smsUrl($this->tenant, '/campaigns'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('campaigns.data', 1)->where('campaigns.data.0.name', 'Mine'));

    $this->get($this->smsUrl($this->tenant, "/campaigns/{$mine->id}"))->assertOk();
    $this->get($this->smsUrl($this->tenant, "/campaigns/{$theirs->id}"))->assertNotFound();
    $this->get($this->smsUrl($this->tenant, "/campaigns/{$theirs->id}/export"))->assertNotFound();
});

it('lets a member with launch permission start a campaign, and records who launched it', function () {
    [$user, $membership] = $this->smsMember($this->tenant, ['sms_marketing.campaigns.view', 'sms_marketing.campaigns.launch']);
    $this->actingAsMember($this->tenant, $user);
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);

    $this->post($this->smsUrl($this->tenant, "/campaigns/{$campaign->id}/launch"))->assertRedirect();

    $this->inTenant($this->tenant, function () use ($campaign, $membership): void {
        $fresh = Campaign::query()->findOrFail($campaign->id);
        expect($fresh->launched_by)->toBe($membership->id)
            ->and($fresh->status)->toBeIn([CampaignStatus::Sending, CampaignStatus::Completed]);
    });
});

it('lists group members only for contacts the viewer may see, and says how many are hidden', function () {
    [$user] = $this->smsMember($this->tenant, ['sms_marketing.groups.view']);
    $this->actingAsMember($this->tenant, $user);

    $this->get($this->smsUrl($this->tenant, "/groups/{$this->group->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('members.data', 0)->where('hiddenMembers', 1)->where('group.membersCount', 1));

    $csv = $this->get($this->smsUrl($this->tenant, "/groups/{$this->group->id}/export"))->assertOk()->streamedContent();
    expect($csv)->not->toContain('+8801711000001');

    [$seer] = $this->smsMember($this->tenant, ['sms_marketing.groups.view', 'core.contacts.view']);
    $this->actingAsMember($this->tenant, $seer);

    $this->get($this->smsUrl($this->tenant, "/groups/{$this->group->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('members.data', 1)->where('hiddenMembers', 0));
});
