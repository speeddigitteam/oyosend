<?php

declare(strict_types=1);

use App\Core\Billing\Models\Plan;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\ActivityManager;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Enums\ActivityType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\DealStatus;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\DealStageHistory;
use App\Modules\CRM\Models\TimelineEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

it('moves a shared customer through lead qualification, a won deal and follow-up activity', function () {
    $tenant = $this->createTenant('acme');

    $result = $this->inTenant($tenant, function () use ($tenant): array {
        $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $directory = app(ContactDirectory::class);
        $company = $directory->createCompany(['name' => 'Northwind'], $owner);
        $contact = $directory->createContact(['first_name' => 'Nadia', 'company_id' => $company->id], $owner);
        $pipeline = app(PipelineManager::class)->create('Sales', [
            ['name' => 'Discovery', 'probability' => 20],
            ['name' => 'Won', 'probability' => 100, 'is_won' => true],
        ]);
        $lead = app(LeadManager::class)->create(['title' => 'Website enquiry', 'contact_id' => $contact->id, 'company_id' => $company->id, 'score' => 40], $owner);
        app(LeadManager::class)->qualify($lead, 85, 'Budget and decision maker confirmed.', $owner);
        $deal = app(DealManager::class)->create(['title' => 'Annual agreement', 'lead_id' => $lead->id, 'currency' => 'BDT', 'amount' => 250000], $pipeline, $pipeline->stages[0], $owner, $owner);
        app(DealManager::class)->move($deal, $pipeline->stages[1], $owner);
        expect(fn () => app(DealManager::class)->move($deal, $pipeline->stages[0], $owner))
            ->toThrow(ValidationException::class, 'closed deal');
        $activity = app(ActivityManager::class)->create(['type' => ActivityType::FollowUp, 'priority' => ActivityPriority::High, 'subject' => 'Arrange kickoff', 'deal_id' => $deal->id, 'contact_id' => $contact->id, 'due_at' => now()->addDay()], $owner, $owner);
        app(ActivityManager::class)->complete($activity, $owner);

        return [$lead->refresh(), $deal->refresh(), $activity->refresh(), $contact, $company];
    });

    [$lead, $deal, $activity, $contact, $company] = $result;
    $profile = CustomerProfile::forTenant($tenant)->where('contact_id', $contact->id)->firstOrFail();
    expect($lead->status)->toBe(LeadStatus::Converted)
        ->and($lead->score)->toBe(85)
        ->and($deal->status)->toBe(DealStatus::Won)
        ->and($deal->contact_id)->toBe($contact->id)
        ->and($deal->company_id)->toBe($company->id)
        ->and($activity->status)->toBe(ActivityStatus::Completed)
        ->and($profile->stage)->toBe(CustomerLifecycleStage::Customer)
        ->and($profile->lifetime_values)->toBe(['BDT' => 250000])
        ->and(DealStageHistory::forTenant($tenant)->where('deal_id', $deal->id)->count())->toBe(2)
        ->and(TimelineEntry::forTenant($tenant)->pluck('event'))->toContain('crm.lead.created', 'crm.lead.qualified', 'crm.deal.created', 'crm.deal.stage_changed', 'crm.activity.completed');
});

it('refuses shared contacts from another workspace', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');
    $foreign = $this->inTenant($globex, fn (): Contact => app(ContactDirectory::class)->createContact(['first_name' => 'Foreign']));

    $this->inTenant($acme, fn () => app(LeadManager::class)->create(['title' => 'Invalid', 'contact_id' => $foreign->id]));
})->throws(ModelNotFoundException::class);

it('serves scoped option contracts and hides a foreign lead route', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');
    $plan = Plan::factory()->free()->create();
    $this->subscribe($acme, $plan, withTrial: false);
    $this->subscribe($globex, $plan, withTrial: false);
    $this->entitle($acme, 'crm');
    $this->entitle($globex, 'crm');
    $contact = $this->inTenant($acme, fn (): Contact => app(ContactDirectory::class)->createContact(['first_name' => 'Nadia']));
    $lead = $this->inTenant($acme, fn () => app(LeadManager::class)->create(['title' => 'Scoped lead', 'contact_id' => $contact->id]));

    $this->actingAsOwner($acme)->get($this->tenantUrl($acme, '/crm/leads'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Modules/CRM/Pages/Leads/Index')->has('contacts', 1)->has('companies')->has('owners')->has('sources'));

    $this->startNewBrowserSession()->actingAsOwner($globex)
        ->get($this->tenantUrl($globex, '/crm/leads/'.$lead->id))
        ->assertNotFound();
});
