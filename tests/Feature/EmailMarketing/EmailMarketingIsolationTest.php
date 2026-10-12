<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\EnrollmentStatus;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Enums\SequenceStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\AutomationActionReceipt;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Enrollment;
use App\Modules\EmailMarketing\Models\ImportRun;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Models\Setting;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Models\Template;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

$list = fn (): MailingList => MailingList::query()->create(['name' => 'L '.Str::random(6), 'status' => ListStatus::Active]);
$contact = fn (): Contact => Contact::factory()->create();
$campaign = fn () => Campaign::query()->create([
    'name' => 'C', 'subject' => 'S', 'blocks' => [['type' => 'divider']], 'list_id' => $list()->id, 'status' => CampaignStatus::Draft,
]);
$sequence = fn () => Sequence::query()->create(['name' => 'Q '.Str::random(6), 'list_id' => $list()->id, 'status' => SequenceStatus::Draft]);

it('isolates lists, segments, subscribers and templates between tenants', function () use ($list, $contact) {
    $this->assertTenantIsolated(MailingList::class, fn () => $list());
    $this->assertTenantIsolated(Segment::class, fn () => Segment::query()->create([
        'list_id' => $list()->id, 'name' => 'S', 'match' => 'all', 'rules' => [['field' => 'city', 'operator' => 'exists', 'value' => null]],
    ]));
    $this->assertTenantIsolated(Subscription::class, fn () => Subscription::query()->create([
        'list_id' => $list()->id, 'contact_id' => $contact()->id, 'status' => SubscriptionStatus::Pending, 'source' => 'manual',
    ]));
    $this->assertTenantIsolated(Template::class, fn () => Template::query()->create(['name' => 'T', 'blocks' => [['type' => 'divider']]]));
});

it('isolates campaigns, deliveries and the settings of a workspace', function () use ($campaign, $contact) {
    $this->assertTenantIsolated(Campaign::class, fn () => $campaign());
    $this->assertTenantIsolated(Delivery::class, fn () => Delivery::query()->create([
        'campaign_id' => $campaign()->id, 'contact_id' => $contact()->id, 'send_key' => 'k:'.Str::random(10), 'status' => DeliveryStatus::Pending,
    ]));
    $this->assertTenantIsolated(Setting::class, fn () => Setting::query()->create([]));
    $this->assertTenantIsolated(ImportRun::class, fn () => ImportRun::query()->create([
        'list_id' => MailingList::query()->create(['name' => 'I', 'status' => ListStatus::Active])->id,
        'file_name' => 'a.csv', 'file_sha256' => hash('sha256', Str::random(10)),
    ]));
});

it('isolates sequences, steps, enrolments and automation receipts', function () use ($sequence, $contact) {
    $this->assertTenantIsolated(Sequence::class, fn () => $sequence());
    $this->assertTenantIsolated(SequenceStep::class, fn () => SequenceStep::query()->create([
        'sequence_id' => $sequence()->id, 'position' => 1, 'delay_minutes' => 0, 'subject' => 'S', 'blocks' => [['type' => 'divider']],
    ]));
    $this->assertTenantIsolated(Enrollment::class, fn () => Enrollment::query()->create([
        'sequence_id' => $sequence()->id, 'contact_id' => $contact()->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(),
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'email_marketing.subscriber.add_to_list', 'idempotency_key' => Str::random(12), 'output' => [],
    ]));
});

it('answers 404 from another workspace for every route that takes an id', function () {
    $home = $this->emTenant('em-home');
    $intruder = $this->emTenant('em-intruder');
    $list = $this->emList($home);
    $contact = $this->emContact($home);
    $subscription = $this->emSubscribe($home, $list, $contact);
    $campaign = $this->emCampaign($home, $list);
    $segment = $this->inTenant($home, fn () => Segment::query()->create([
        'list_id' => $list->id, 'name' => 'S', 'match' => 'all', 'rules' => [['field' => 'city', 'operator' => 'exists', 'value' => null]],
    ]));
    $template = $this->inTenant($home, fn () => Template::query()->create(['name' => 'T', 'blocks' => [['type' => 'divider']]]));
    $sequence = $this->inTenant($home, fn () => Sequence::query()->create(['name' => 'Q', 'list_id' => $list->id, 'status' => SequenceStatus::Draft]));

    $check = fn (string $method, string $path, array $data = []) => $this->assertNotFoundFromOtherTenant($method, '/email-marketing'.$path, $home, $data, $intruder);

    $check('GET', "/campaigns/{$campaign->id}");
    $check('GET', "/campaigns/{$campaign->id}/edit");
    $check('PUT', "/campaigns/{$campaign->id}", ['name' => 'x', 'subject' => 'x', 'list_id' => $list->id, 'blocks' => [['type' => 'divider']]]);
    $check('POST', "/campaigns/{$campaign->id}/duplicate");
    $check('POST', "/campaigns/{$campaign->id}/schedule");
    $check('POST', "/campaigns/{$campaign->id}/unschedule");
    $check('POST', "/campaigns/{$campaign->id}/pause");
    $check('POST', "/campaigns/{$campaign->id}/resume");
    $check('POST', "/campaigns/{$campaign->id}/cancel");
    $check('POST', "/campaigns/{$campaign->id}/test", ['recipients' => [str_repeat('A', 26)]]);
    $check('GET', "/campaigns/{$campaign->id}/deliveries/export");
    $check('GET', "/templates/{$template->id}/edit");
    $check('PUT', "/templates/{$template->id}", ['name' => 'x', 'blocks' => [['type' => 'divider']]]);
    $check('GET', "/lists/{$list->id}");
    $check('PUT', "/lists/{$list->id}", ['name' => 'x']);
    $check('GET', "/lists/{$list->id}/export");
    $check('POST', "/lists/{$list->id}/segments", ['name' => 'x', 'match' => 'all', 'rules' => [['field' => 'city', 'operator' => 'exists']]]);
    $check('POST', "/lists/{$list->id}/subscribers", ['contact_id' => $contact->id]);
    $check('PUT', "/segments/{$segment->id}", ['name' => 'x', 'match' => 'all', 'rules' => [['field' => 'city', 'operator' => 'exists']]]);
    $check('PUT', "/subscriptions/{$subscription->id}", ['action' => 'properties', 'properties' => []]);
    $check('POST', "/subscriptions/{$subscription->id}/unsubscribe");
    $check('GET', "/sequences/{$sequence->id}");
    $check('GET', "/sequences/{$sequence->id}/edit");
    $check('PUT', "/sequences/{$sequence->id}", ['name' => 'x', 'list_id' => $list->id, 'steps' => []]);
    $check('POST', "/sequences/{$sequence->id}/activate");
    $check('POST', "/sequences/{$sequence->id}/pause");

    // Deleting last: the control request in the home workspace really deletes.
    $check('DELETE', "/campaigns/{$campaign->id}");
    $check('DELETE', "/templates/{$template->id}");
    $check('DELETE', "/segments/{$segment->id}");
    $check('DELETE', "/subscriptions/{$subscription->id}");
    $check('DELETE', "/sequences/{$sequence->id}");
    $check('DELETE', "/lists/{$list->id}");
});

it('cannot reach another workspace\'s list from a campaign or a contact search', function () {
    $home = $this->emTenant('em-a');
    $other = $this->emTenant('em-b');
    $foreignList = $this->emList($other, 'Foreign');
    $foreignContact = $this->emContact($other, ['first_name' => 'Zed', 'email' => 'zed@example.test']);
    $this->actingAsOwner($home);

    $this->post($this->emUrl($home, '/campaigns'), [
        'name' => 'X', 'subject' => 'S', 'blocks' => [['type' => 'divider']], 'list_id' => $foreignList->id,
    ])->assertSessionHasErrors('list_id');

    $this->getJson($this->emUrl($home, '/subscribers/contacts?q=zed'))->assertOk()->assertExactJson([]);

    $homeList = $this->emList($home);
    $this->post($this->emUrl($home, "/lists/{$homeList->id}/subscribers"), ['contact_id' => $foreignContact->id])->assertNotFound();
});
