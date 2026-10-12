<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Enums\RegistrationStatus;
use App\Modules\SmsMarketing\Enums\SenderIdType;
use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use App\Modules\SmsMarketing\Models\AutomationActionReceipt;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\CampaignGroup;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\GroupMember;
use App\Modules\SmsMarketing\Models\Import;
use App\Modules\SmsMarketing\Models\Recipient;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Models\Setting;
use App\Modules\SmsMarketing\Models\Template;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithSmsMarketing;

uses(InteractsWithSmsMarketing::class);

$group = fn (): Group => Group::query()->create(['name' => 'G '.Str::random(6)]);
$campaign = fn (): Campaign => Campaign::query()->create(['name' => 'C '.Str::random(6), 'body' => 'Hello', 'status' => CampaignStatus::Draft]);
$contact = fn (): Contact => Contact::factory()->create(['phone' => '+8801711000001']);

it('isolates every SMS Marketing model between workspaces', function () use ($group, $campaign, $contact) {
    $this->assertTenantIsolated(Setting::class, fn () => Setting::query()->create([]));
    $this->assertTenantIsolated(SenderId::class, fn () => SenderId::query()->create([
        'label' => 'Brand', 'value' => 'ACME', 'type' => SenderIdType::Masking, 'registration_status' => RegistrationStatus::NotRegistered,
    ]));
    $this->assertTenantIsolated(Group::class, fn () => $group());
    $this->assertTenantIsolated(GroupMember::class, fn () => GroupMember::query()->create(['group_id' => $group()->id, 'contact_id' => $contact()->id]));
    $this->assertTenantIsolated(Template::class, fn () => Template::query()->create(['name' => 'T', 'purpose' => TemplatePurpose::Marketing, 'body' => 'Hi', 'is_active' => true]));
    $this->assertTenantIsolated(Campaign::class, fn () => $campaign());
    $this->assertTenantIsolated(CampaignGroup::class, fn () => CampaignGroup::query()->create(['campaign_id' => $campaign()->id, 'group_id' => $group()->id]));
    $this->assertTenantIsolated(Recipient::class, fn () => Recipient::query()->create([
        'campaign_id' => $campaign()->id, 'contact_id' => $contact()->id, 'status' => RecipientStatus::Pending,
    ]));
    $this->assertTenantIsolated(Import::class, fn () => Import::query()->create(['file_name' => 'a.csv']));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'sms_marketing.group.add_contact', 'idempotency_key' => Str::random(12), 'output' => [],
    ]));
});

it('answers 404 from another workspace for every route that takes an id', function () {
    $home = $this->smsTenant('sms-home');
    $intruder = $this->smsTenant('sms-intruder');
    $this->connectFakeProvider($home);

    $contact = $this->smsContact($home);
    $group = $this->smsGroup($home, [$contact->id]);
    $campaign = $this->smsCampaign($home, [$group->id]);
    $member = $this->inTenant($home, fn () => GroupMember::query()->where('group_id', $group->id)->sole());
    $template = $this->inTenant($home, fn () => Template::query()->create(['name' => 'T', 'purpose' => TemplatePurpose::Marketing, 'body' => 'Hi', 'is_active' => true]));
    $sender = $this->inTenant($home, fn () => SenderId::query()->create([
        'label' => 'Brand', 'value' => 'ACME', 'type' => SenderIdType::Masking, 'registration_status' => RegistrationStatus::NotRegistered,
    ]));

    $check = fn (string $method, string $path, array $data = []) => $this->assertNotFoundFromOtherTenant($method, '/sms-marketing'.$path, $home, $data, $intruder);

    $check('GET', "/campaigns/{$campaign->id}");
    $check('GET', "/campaigns/{$campaign->id}/edit");
    $check('GET', "/campaigns/{$campaign->id}/export");
    $check('PUT', "/campaigns/{$campaign->id}", ['name' => 'N', 'body' => 'Hi', 'group_ids' => [$group->id]]);
    $check('POST', "/campaigns/{$campaign->id}/cancel");
    $check('POST', "/campaigns/{$campaign->id}/retry");
    $check('GET', "/groups/{$group->id}");
    $check('GET', "/groups/{$group->id}/export");
    $check('PUT', "/groups/{$group->id}", ['name' => 'Renamed']);
    $check('POST', "/groups/{$group->id}/members", ['contact_ids' => [$contact->id]]);
    $check('DELETE', "/groups/{$group->id}/members/{$member->id}");
    $check('PUT', "/templates/{$template->id}", ['name' => 'T2', 'purpose' => 'marketing', 'body' => 'Hi', 'is_active' => true]);
    $check('DELETE', "/templates/{$template->id}");
    $check('PUT', "/sender-ids/{$sender->id}", [
        'label' => 'B', 'value' => 'ACME', 'type' => 'masking', 'registration_status' => 'not_registered',
    ]);
    $check('DELETE', "/sender-ids/{$sender->id}");
    $check('DELETE', "/campaigns/{$campaign->id}");
});

it('keeps another workspace\'s group, template and sender id out of a campaign', function () {
    $home = $this->smsTenant('sms-a');
    $other = $this->smsTenant('sms-b');
    $foreignGroup = $this->smsGroup($other, [$this->smsContact($other)->id]);
    $foreignTemplate = $this->inTenant($other, fn () => Template::query()->create(['name' => 'T', 'purpose' => TemplatePurpose::Marketing, 'body' => 'Hi', 'is_active' => true]));
    $homeGroup = $this->smsGroup($home, [$this->smsContact($home)->id]);

    $this->actingAsOwner($home);

    $this->post($this->smsUrl($home, '/campaigns'), ['name' => 'X', 'body' => 'Hi', 'group_ids' => [$foreignGroup->id]])
        ->assertSessionHasErrors('groups');
    $this->post($this->smsUrl($home, '/campaigns'), ['name' => 'X', 'template_id' => $foreignTemplate->id, 'group_ids' => [$homeGroup->id]])
        ->assertSessionHasErrors('template_id');
    $this->post($this->smsUrl($home, '/campaigns'), ['name' => 'X', 'body' => 'Hi', 'group_ids' => [$homeGroup->id], 'sender_identity_id' => (string) Str::ulid()])
        ->assertSessionHasErrors('sender_identity_id');

    $this->inTenant($home, fn () => expect(Campaign::query()->count())->toBe(0));
});

it('answers 404 for a workspace that is not entitled to the app, even for its owner', function () {
    $tenant = $this->commTenant('sms-no-app');
    $this->actingAsOwner($tenant);

    foreach (['/campaigns', '/groups', '/templates', '/sender-ids', '/analytics', '/settings'] as $path) {
        $this->get($this->smsUrl($tenant, $path))->assertNotFound();
    }
});

it('answers 404 when the communication app it depends on is missing', function () {
    // A real plan that includes SMS Marketing but not Communication: the dependency removes the app.
    $tenant = $this->createTenant('sms-no-comm');
    $this->subscribe($tenant, $this->createFreePlan(['modules' => ['sms_marketing']]));
    $this->actingAsOwner($tenant);

    $this->get($this->smsUrl($tenant, '/campaigns'))->assertNotFound();

    // With both in the plan the same workspace reaches it.
    $other = $this->createTenant('sms-with-comm');
    $this->subscribe($other, $this->createFreePlan(['modules' => ['sms_marketing', 'communication']]));
    $this->actingAsOwner($other);

    $this->get($this->smsUrl($other, '/campaigns'))->assertOk();
});
