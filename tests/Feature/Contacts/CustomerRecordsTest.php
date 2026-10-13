<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Data\InteractionEntry;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\CustomerRecordRegistry;
use App\Core\Contacts\Models\Contact;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\CRM\Actions\ActivityManager;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityType;
use Tests\Support\InteractsWithSupport;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class, InteractsWithSupport::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('customer-360');
    $this->entitle($this->tenant, 'crm', 'support');
    $this->contact = $this->waContact($this->tenant, ['first_name' => 'Rahim', 'last_name' => 'Uddin', 'phone' => '+8801711000001', 'email' => 'rahim@example.test']);
    $this->ownerMembership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->url = $this->tenantUrl($this->tenant, "/contacts/{$this->contact->id}/records");
});

/** @return array<string, array<string, mixed>> */
function sectionsByKey(array $sections): array
{
    return collect($sections)->keyBy('key')->all();
}

it('shows what every entitled app holds about one contact, with links back to each app', function () {
    $membership = $this->ownerMembership;
    $contact = $this->contact;

    $this->inTenant($this->tenant, function () use ($membership, $contact): void {
        $pipeline = app(PipelineManager::class)->create('Sales', [['name' => 'Proposal', 'probability' => 50]], true);
        app(LeadManager::class)->create(['title' => 'Bulk order enquiry', 'contact_id' => $contact->id], $membership);
        app(DealManager::class)->create(['title' => 'Bulk order', 'contact_id' => $contact->id, 'currency' => 'BDT', 'amount' => 2_500_000], $pipeline, $pipeline->stages[0], $membership, $membership);
        app(ActivityManager::class)->create(['type' => ActivityType::FollowUp, 'priority' => ActivityPriority::Normal, 'subject' => 'Send price list', 'contact_id' => $contact->id, 'due_at' => now()->subDay()], $membership, $membership);
        app(InteractionRecorder::class)->record(new InteractionEntry(type: 'note', summary: 'Asked about delivery', contactId: $contact->id));
    });
    $this->waConversation($this->tenant, $contact);
    $this->supportTicket($this->tenant, ['subject' => 'Late delivery', 'contact_id' => $contact->id]);

    $response = $this->actingAsOwner($this->tenant)->getJson($this->url)->assertOk();
    $sections = sectionsByKey($response->json('sections'));

    expect(array_keys($sections))->toBe(['crm.leads', 'crm.deals', 'crm.activities', 'whatsapp_marketing.conversations', 'support.tickets', 'communication.timeline'])
        ->and($response->json('contact.name'))->toBe('Rahim Uddin')
        ->and($sections['crm.leads']['items'][0]['title'])->toBe('Bulk order enquiry')
        ->and($sections['crm.leads']['actions'][0]['key'])->toBe('create_lead')
        ->and($sections['crm.leads']['actions'][0]['url'])->toContain('create_for='.$contact->id)
        ->and($sections['crm.deals']['items'][0]['detail'])->toContain('Proposal')
        ->and($sections['crm.activities']['items'][0]['status'])->toBe('Overdue')
        ->and($sections['crm.activities']['items'][0]['tone'])->toBe('danger')
        ->and($sections['support.tickets']['items'][0]['title'])->toContain('Late delivery')
        ->and($sections['communication.timeline']['items'][0]['title'])->toBe('Asked about delivery')
        ->and($sections['whatsapp_marketing.conversations']['items'][0]['url'])->toContain('/whatsapp/inbox/');
});

it('shows a member only the apps they may use and only the records in their scope', function () {
    $owner = $this->ownerMembership;
    $contact = $this->contact;
    [$user, $membership] = $this->waMember($this->tenant, ['core.contacts.view', 'crm.leads.view'], ['core.contacts.view' => 'all', 'crm.leads.view' => 'own']);

    $this->inTenant($this->tenant, function () use ($owner, $membership, $contact): void {
        app(LeadManager::class)->create(['title' => 'Mine', 'contact_id' => $contact->id], $membership);
        app(LeadManager::class)->create(['title' => 'Someone else', 'contact_id' => $contact->id], $owner);
    });

    $sections = sectionsByKey($this->actingAsMember($this->tenant, $user)->getJson($this->url)->assertOk()->json('sections'));

    expect(array_keys($sections))->toBe(['crm.leads'])
        ->and($sections['crm.leads']['total'])->toBe(1)
        ->and($sections['crm.leads']['items'][0]['title'])->toBe('Mine')
        ->and($sections['crm.leads']['actions'])->toBe([]);
});

it('leaves out the sections of an app the workspace is not entitled to', function () {
    $other = $this->createTenant('customer-360-crm-only');
    $this->entitle($other, 'crm');
    $contact = $this->inTenant($other, fn () => Contact::factory()->create());

    $keys = array_column($this->actingAsOwner($other)->getJson($this->tenantUrl($other, "/contacts/{$contact->id}/records"))->assertOk()->json('sections'), 'key');

    expect($keys)->toBe(['crm.leads', 'crm.deals', 'crm.activities']);
});

it('refuses a contact outside the member\'s contact scope and one from another workspace', function () {
    [$user] = $this->waMember($this->tenant, ['core.contacts.view', 'crm.leads.view'], ['core.contacts.view' => 'own', 'crm.leads.view' => 'all']);

    $this->actingAsMember($this->tenant, $user)->getJson($this->url)->assertNotFound();
    $this->assertNotFoundFromOtherTenant('GET', "/contacts/{$this->contact->id}/records", $this->tenant);
});

it('rejects two sections with the same key', function () {
    $section = new class implements CustomerRecordProvider
    {
        public function definition(): CustomerSectionDefinition
        {
            return new CustomerSectionDefinition('crm.leads', 'crm', 'Copy', 'crm.leads.view');
        }

        public function forContact(CustomerQuery $query): ?CustomerSection
        {
            return null;
        }
    };
    $this->app->instance('test.duplicate_section', $section);
    $this->app->tag('test.duplicate_section', CustomerRecordProvider::TAG);

    $this->inTenant($this->tenant, fn () => app(CustomerRecordRegistry::class)->all());
})->throws(LogicException::class, 'Duplicate customer section [crm.leads].');

it('opens the CRM create forms with the contact from the customer view, only when the member may see it', function () {
    $response = $this->actingAsOwner($this->tenant)->get($this->tenantUrl($this->tenant, "/crm/leads?create_for={$this->contact->id}"))->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->where('prefillContact.id', $this->contact->id)
        ->where('prefillContact.name', 'Rahim Uddin'));

    $this->actingAsOwner($this->tenant)->get($this->tenantUrl($this->tenant, "/crm/activities?create_for={$this->contact->id}"))
        ->assertInertia(fn ($page) => $page->where('prefillContact.id', $this->contact->id));

    [$user] = $this->waMember($this->tenant, ['core.contacts.view', 'crm.deals.view', 'crm.deals.edit'], ['core.contacts.view' => 'own', 'crm.deals.view' => 'all', 'crm.deals.edit' => 'all']);
    $this->actingAsMember($this->tenant, $user)->get($this->tenantUrl($this->tenant, "/crm/deals?create_for={$this->contact->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('prefillContact', null));
});
