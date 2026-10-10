<?php

declare(strict_types=1);

use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\ActivityManager;
use App\Modules\CRM\Actions\ContractManager;
use App\Modules\CRM\Actions\CrmContentManager;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Actions\QuoteManager;
use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Enums\ActivityType;
use App\Modules\CRM\Enums\ContractStatus;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\CustomFieldType;
use App\Modules\CRM\Enums\DealStatus;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Contract;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\Segment;
use Illuminate\Validation\ValidationException;

it('does not disclose related deals without permission and applies the deal record scope', function () {
    $tenant = $this->createTenant('crm-related-deal-scope');
    $this->entitle($tenant, 'crm');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);

    [$leadOnlyRole, $scopedRole] = $this->inTenant($tenant, fn (): array => [
        app(RoleManager::class)->create(
            $owner,
            'Lead reader',
            null,
            ['crm.leads.view'],
            ['crm.leads.view' => 'all'],
        ),
        app(RoleManager::class)->create(
            $owner,
            'Scoped deal reader',
            null,
            ['crm.leads.view', 'crm.deals.view'],
            ['crm.leads.view' => 'all', 'crm.deals.view' => 'own'],
        ),
    ]);
    $leadOnlyUser = $this->addMember($tenant, $leadOnlyRole);
    $scopedUser = $this->addMember($tenant, $scopedRole);
    $scopedMembership = $this->membershipOf($tenant, $scopedUser);

    [$lead, $ownDeal] = $this->inTenant($tenant, function () use ($ownerMembership, $scopedMembership): array {
        $contact = Contact::factory()->create(['first_name' => 'Scoped', 'last_name' => 'Buyer']);
        $pipeline = app(PipelineManager::class)->create('Related-deal scope', [
            ['name' => 'Open', 'probability' => 25],
        ]);
        $lead = app(LeadManager::class)->create([
            'title' => 'Visible lead',
            'contact_id' => $contact->id,
        ], $ownerMembership);
        app(DealManager::class)->create([
            'title' => 'Foreign related deal',
            'lead_id' => $lead->id,
            'currency' => 'BDT',
            'amount' => 90_000,
        ], $pipeline, $pipeline->stages[0], $ownerMembership, $ownerMembership);
        $ownDeal = app(DealManager::class)->create([
            'title' => 'Own related deal',
            'lead_id' => $lead->id,
            'currency' => 'BDT',
            'amount' => 45_000,
        ], $pipeline, $pipeline->stages[0], $scopedMembership, $ownerMembership);

        return [$lead, $ownDeal];
    });

    $this->actingAsMember($tenant, $leadOnlyUser)
        ->get($this->tenantUrl($tenant, "/crm/leads/{$lead->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/CRM/Pages/Leads/Show')
            ->has('deals', 0));

    $this->startNewBrowserSession()
        ->actingAsMember($tenant, $scopedUser)
        ->get($this->tenantUrl($tenant, "/crm/leads/{$lead->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/CRM/Pages/Leads/Show')
            ->has('deals', 1)
            ->where('deals.0.id', $ownDeal->id)
            ->where('deals.0.title', 'Own related deal'));
});

it('interprets activity datetimes in the workspace timezone and exposes the cancel endpoint', function () {
    $tenant = $this->createTenant('crm-activity-timezone', ['timezone' => 'Asia/Dhaka']);
    $this->entitle($tenant, 'crm');

    $this->actingAsOwner($tenant)
        ->post($this->tenantUrl($tenant, '/crm/activities'), [
            'type' => ActivityType::Meeting->value,
            'priority' => ActivityPriority::High->value,
            'subject' => 'Dhaka morning meeting',
            'starts_at' => '2026-10-12 09:30:00',
            'due_at' => '2026-10-12 10:30:00',
            'remind_at' => '2026-10-12 10:00:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $activity = $this->inTenant($tenant, fn (): Activity => Activity::query()->where('subject', 'Dhaka morning meeting')->sole());

    expect($activity->starts_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 03:30:00')
        ->and($activity->due_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 04:30:00')
        ->and($activity->remind_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 04:00:00');

    $this->post($this->tenantUrl($tenant, "/crm/activities/{$activity->id}/cancel"))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->inTenant($tenant, fn () => $activity->refresh()->status))->toBe(ActivityStatus::Canceled);
});

it('applies saved lead deal and activity segments to their indexes', function () {
    $tenant = $this->createTenant('crm-saved-segments');
    $this->entitle($tenant, 'crm');
    $owner = $this->ownerOf($tenant);
    $membership = $this->membershipOf($tenant, $owner);

    [$leadSegment, $dealSegment, $activitySegment] = $this->inTenant($tenant, function () use ($membership): array {
        $contact = Contact::factory()->create(['first_name' => 'Segment', 'last_name' => 'Buyer']);
        $pipeline = app(PipelineManager::class)->create('Segment pipeline', [
            ['name' => 'Open', 'probability' => 30],
        ]);
        $highLead = app(LeadManager::class)->create([
            'title' => 'High score lead',
            'contact_id' => $contact->id,
            'score' => 90,
        ], $membership);
        app(LeadManager::class)->create([
            'title' => 'Low score lead',
            'contact_id' => $contact->id,
            'score' => 20,
        ], $membership);
        app(DealManager::class)->create([
            'title' => 'Small deal',
            'lead_id' => $highLead->id,
            'currency' => 'BDT',
            'amount' => 20_000,
        ], $pipeline, $pipeline->stages[0], $membership, $membership);
        app(DealManager::class)->create([
            'title' => 'Large deal',
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'amount' => 250_000,
        ], $pipeline, $pipeline->stages[0], $membership, $membership);
        app(ActivityManager::class)->create([
            'type' => ActivityType::Call,
            'priority' => ActivityPriority::Normal,
            'subject' => 'Segment call',
        ], $membership, $membership);
        app(ActivityManager::class)->create([
            'type' => ActivityType::Meeting,
            'priority' => ActivityPriority::Normal,
            'subject' => 'Segment meeting',
        ], $membership, $membership);

        return [
            Segment::query()->create([
                'name' => 'High scores',
                'entity_type' => CrmEntityType::Lead,
                'filters' => ['min_score' => 80],
                'is_active' => true,
                'created_by' => $membership->id,
            ]),
            Segment::query()->create([
                'name' => 'Large opportunities',
                'entity_type' => CrmEntityType::Deal,
                'filters' => ['min_amount' => 100_000],
                'is_active' => true,
                'created_by' => $membership->id,
            ]),
            Segment::query()->create([
                'name' => 'Calls',
                'entity_type' => CrmEntityType::Activity,
                'filters' => ['type' => ActivityType::Call->value],
                'is_active' => true,
                'created_by' => $membership->id,
            ]),
        ];
    });

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, "/crm/leads?segment={$leadSegment->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.title', 'High score lead'));

    $this->get($this->tenantUrl($tenant, "/crm/deals?segment={$dealSegment->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deals.data', 1)
            ->where('deals.data.0.title', 'Large deal'));

    $this->get($this->tenantUrl($tenant, "/crm/activities?segment={$activitySegment->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.subject', 'Segment call'));
});

it('serves customer content timelines and contact segments without crossing workspaces', function () {
    $tenant = $this->createTenant('crm-customer-content');
    $this->entitle($tenant, 'crm');
    $membership = $this->membershipOf($tenant, $this->ownerOf($tenant));

    [$profile, $segment] = $this->inTenant($tenant, function () use ($membership): array {
        $contact = Contact::factory()->create([
            'first_name' => 'Nadia',
            'last_name' => 'Rahman',
            'email' => 'nadia@example.test',
        ]);
        $other = Contact::factory()->create([
            'first_name' => 'Unrelated',
            'last_name' => 'Customer',
        ]);
        $profile = CustomerProfile::query()->create([
            'contact_id' => $contact->id,
            'stage' => CustomerLifecycleStage::Customer,
            'lifetime_values' => ['BDT' => 125_000],
        ]);
        CustomerProfile::query()->create([
            'contact_id' => $other->id,
            'stage' => CustomerLifecycleStage::Lead,
        ]);
        $content = app(CrmContentManager::class);
        $content->createCustomField([
            'entity_type' => CrmEntityType::Contact,
            'key' => 'account_tier',
            'name' => 'Account tier',
            'type' => CustomFieldType::Text,
            'is_active' => true,
        ]);
        $content->setCustomValues(CrmEntityType::Contact, $contact->id, ['account_tier' => 'Gold']);
        $tag = $content->createTag('Priority');
        $content->syncTags(CrmEntityType::Contact, $contact->id, [$tag->id]);
        $content->addNote('Customer success handoff complete.', ['contact_id' => $contact->id], $membership, true);
        $segment = $content->createSegment('Rahman contacts', CrmEntityType::Contact, ['search' => 'Rahman'], $membership);

        return [$profile, $segment];
    });

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, "/crm/sales/customers?segment={$segment->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/CRM/Pages/Sales/Customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $profile->id));

    $this->get($this->tenantUrl($tenant, "/crm/sales/customers/{$profile->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/CRM/Pages/Sales/Customers/Show')
            ->where('entityType', 'contact')
            ->where('content.notes.0.body', 'Customer success handoff complete.')
            ->where('content.tags.0.name', 'Priority')
            ->where('content.customFields.0.value', 'Gold')
            ->where('timeline.0.event', 'crm.note.created'));

    $this->post($this->tenantUrl($tenant, "/crm/sales/customers/{$profile->id}/notes"), [
        'body' => 'Renewal follow-up scheduled.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $otherTenant = $this->createTenant('crm-customer-content-other');
    $this->entitle($otherTenant, 'crm');
    $this->startNewBrowserSession()->actingAsOwner($otherTenant)
        ->get($this->tenantUrl($otherTenant, "/crm/sales/customers/{$profile->id}"))
        ->assertNotFound();
    $this->post($this->tenantUrl($otherTenant, "/crm/sales/customers/{$profile->id}/notes"), [
        'body' => 'Cross-workspace write.',
    ])->assertNotFound();
});

it('does not offer unsupported activity custom fields', function () {
    $tenant = $this->createTenant('crm-custom-field-surfaces');
    $this->entitle($tenant, 'crm');

    $this->actingAsOwner($tenant)
        ->post($this->tenantUrl($tenant, '/crm/settings/custom-fields'), [
            'entity_type' => CrmEntityType::Activity->value,
            'key' => 'unsupported_activity_field',
            'name' => 'Unsupported activity field',
            'type' => CustomFieldType::Text->value,
        ])
        ->assertSessionHasErrors('entity_type');
});

it('publishes per-record quote and contract actions and enforces them on mutation routes', function () {
    $tenant = $this->createTenant('crm-record-flags');
    $this->entitle($tenant, 'crm');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $owner,
        'Own commercial records',
        null,
        ['core.contacts.view', 'crm.quotes.view', 'crm.quotes.manage', 'crm.contracts.view', 'crm.contracts.manage'],
        [
            'core.contacts.view' => 'all',
            'crm.quotes.view' => 'all',
            'crm.quotes.manage' => 'own',
            'crm.contracts.view' => 'all',
            'crm.contracts.manage' => 'own',
        ],
    ));
    $seller = $this->addMember($tenant, $role);
    $sellerMembership = $this->membershipOf($tenant, $seller);

    [$ownQuote, $foreignQuote] = $this->inTenant($tenant, function () use ($ownerMembership, $sellerMembership): array {
        $contact = Contact::factory()->create(['first_name' => 'Commercial', 'last_name' => 'Buyer']);
        $quoteAttributes = [
            'contact_id' => $contact->id,
            'kind' => QuoteKind::Quotation->value,
            'currency' => 'BDT',
            'probability_percent' => 50,
        ];
        $lines = [[
            'item_type' => 'service',
            'name' => 'Support plan',
            'unit' => 'month',
            'quantity_milli' => 1000,
            'unit_price_amount' => 25_000,
            'discount_amount' => 0,
            'tax_bps' => 0,
        ]];
        $ownQuote = app(QuoteManager::class)->create([
            ...$quoteAttributes,
            'title' => 'Own sent quote',
        ], $lines, $sellerMembership);
        $foreignQuote = app(QuoteManager::class)->create([
            ...$quoteAttributes,
            'title' => 'Foreign sent quote',
        ], $lines, $ownerMembership);
        app(QuoteManager::class)->send($ownQuote);
        app(QuoteManager::class)->send($foreignQuote);

        app(ContractManager::class)->create([
            'contact_id' => $contact->id,
            'title' => 'Own draft contract',
            'currency' => 'BDT',
            'value_amount' => 25_000,
            'starts_on' => '2026-01-01',
        ], $sellerMembership);
        app(ContractManager::class)->create([
            'contact_id' => $contact->id,
            'title' => 'Foreign draft contract',
            'currency' => 'BDT',
            'value_amount' => 50_000,
            'starts_on' => '2026-02-01',
        ], $ownerMembership);

        return [$ownQuote, $foreignQuote];
    });

    $this->actingAsMember($tenant, $seller)
        ->get($this->tenantUrl($tenant, "/crm/sales/quotes/{$ownQuote->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quote.rejectable', true)
            ->where('quote.cancelable', true));

    $this->get($this->tenantUrl($tenant, "/crm/sales/quotes/{$foreignQuote->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quote.rejectable', false)
            ->where('quote.cancelable', false));

    $this->get($this->tenantUrl($tenant, '/crm/sales/contracts'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('contracts.data', 2)
            ->where('contracts.data.0.title', 'Foreign draft contract')
            ->where('contracts.data.0.editable', false)
            ->where('contracts.data.0.statusable', false)
            ->where('contracts.data.1.title', 'Own draft contract')
            ->where('contracts.data.1.editable', true)
            ->where('contracts.data.1.statusable', true));

    $this->post($this->tenantUrl($tenant, "/crm/sales/quotes/{$foreignQuote->id}/cancel"))
        ->assertNotFound();
    $this->post($this->tenantUrl($tenant, "/crm/sales/quotes/{$ownQuote->id}/cancel"))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->inTenant($tenant, fn () => $ownQuote->refresh()->status))->toBe(QuoteStatus::Canceled)
        ->and($this->inTenant($tenant, fn () => $foreignQuote->refresh()->status))->toBe(QuoteStatus::Sent)
        ->and($this->inTenant($tenant, fn () => Contract::query()->where('title', 'Own draft contract')->sole()->status))->toBe(ContractStatus::Draft);
});

it('rejects moving a closed deal through the HTTP endpoint', function () {
    $tenant = $this->createTenant('crm-closed-deal');
    $this->entitle($tenant, 'crm');
    $membership = $this->membershipOf($tenant, $this->ownerOf($tenant));

    [$deal, $openStage, $wonStage] = $this->inTenant($tenant, function () use ($membership): array {
        $contact = Contact::factory()->create(['first_name' => 'Closed', 'last_name' => 'Buyer']);
        $pipeline = app(PipelineManager::class)->create('Closable pipeline', [
            ['name' => 'Open', 'probability' => 20],
            ['name' => 'Won', 'probability' => 100, 'is_won' => true],
        ]);
        $deal = app(DealManager::class)->create([
            'title' => 'Closed opportunity',
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'amount' => 120_000,
        ], $pipeline, $pipeline->stages[0], $membership, $membership);
        app(DealManager::class)->move($deal, $pipeline->stages[1], $membership);

        return [$deal, $pipeline->stages[0], $pipeline->stages[1]];
    });

    $this->actingAsOwner($tenant)
        ->from($this->tenantUrl($tenant, "/crm/deals/{$deal->id}"))
        ->post($this->tenantUrl($tenant, "/crm/deals/{$deal->id}/stage"), ['stage_id' => $openStage->id])
        ->assertRedirect($this->tenantUrl($tenant, "/crm/deals/{$deal->id}"))
        ->assertSessionHasErrors('stage_id');

    expect($this->inTenant($tenant, fn () => $deal->refresh()->status))->toBe(DealStatus::Won)
        ->and($deal->stage_id)->toBe($wonStage->id);
});

it('preserves qualified and converted lead lifecycle states during ordinary edits', function () {
    $tenant = $this->createTenant('crm-lead-lifecycle-edit');
    $this->entitle($tenant, 'crm');
    $owner = $this->ownerOf($tenant);
    $membership = $this->membershipOf($tenant, $owner);

    [$contact, $lead, $pipeline] = $this->inTenant($tenant, function () use ($membership): array {
        $contact = Contact::factory()->create(['first_name' => 'Lifecycle', 'last_name' => 'Buyer']);
        $lead = app(LeadManager::class)->create([
            'title' => 'Qualified opportunity',
            'contact_id' => $contact->id,
        ], $membership);
        app(LeadManager::class)->qualify($lead, 80, 'Ready for sales.', $membership);
        $pipeline = app(PipelineManager::class)->create('Lifecycle pipeline', [
            ['name' => 'Open', 'probability' => 25],
        ]);

        return [$contact, $lead->refresh(), $pipeline];
    });
    $qualifiedAt = $lead->qualified_at?->toIso8601String();
    $url = $this->tenantUrl($tenant, "/crm/leads/{$lead->id}");

    $this->actingAsOwner($tenant)
        ->from($url)
        ->put($url, [
            'title' => 'Attempted lifecycle rollback',
            'contact_id' => $contact->id,
            'status' => LeadStatus::New->value,
        ])
        ->assertRedirect($url)
        ->assertSessionHasErrors('status');

    $this->put($url, [
        'title' => 'Qualified title edit',
        'contact_id' => $contact->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $qualified = $this->inTenant($tenant, fn () => $lead->refresh());
    expect($qualified->status)->toBe(LeadStatus::Qualified)
        ->and($qualified->qualified_at?->toIso8601String())->toBe($qualifiedAt)
        ->and($qualified->title)->toBe('Qualified title edit');

    $this->inTenant($tenant, fn () => app(DealManager::class)->create([
        'title' => 'Converted deal',
        'lead_id' => $lead->id,
        'currency' => 'BDT',
        'amount' => 50_000,
    ], $pipeline, $pipeline->stages[0], $membership, $membership));
    $converted = $this->inTenant($tenant, fn () => $lead->refresh());
    $convertedAt = $converted->converted_at?->toIso8601String();

    $this->put($url, [
        'title' => 'Attempted conversion rollback',
        'contact_id' => $contact->id,
        'status' => LeadStatus::Contacted->value,
    ])->assertRedirect()->assertSessionHasErrors('status');

    $this->put($url, [
        'title' => 'Converted title edit',
        'contact_id' => $contact->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $converted = $this->inTenant($tenant, fn () => $lead->refresh());
    expect($converted->status)->toBe(LeadStatus::Converted)
        ->and($converted->converted_at?->toIso8601String())->toBe($convertedAt)
        ->and($converted->title)->toBe('Converted title edit');

    expect(fn () => $this->inTenant($tenant, fn () => app(LeadManager::class)->update($lead, [
        'status' => LeadStatus::New->value,
    ], $membership)))->toThrow(ValidationException::class, 'dedicated workflow');
});

it('requires all-scope assignment before selecting another owner or clearing ownership', function () {
    $tenant = $this->createTenant('crm-assignment-scope');
    $this->entitle($tenant, 'crm');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $owner,
        'Own CRM assigner',
        null,
        ['crm.leads.assign', 'crm.deals.assign'],
        ['crm.leads.assign' => 'own', 'crm.deals.assign' => 'own'],
    ));
    $seller = $this->addMember($tenant, $role);
    $target = $this->addMember($tenant);
    $sellerMembership = $this->membershipOf($tenant, $seller);
    $targetMembership = $this->membershipOf($tenant, $target);

    [$lead, $deal] = $this->inTenant($tenant, function () use ($ownerMembership, $sellerMembership): array {
        $contact = Contact::factory()->create(['first_name' => 'Assigned', 'last_name' => 'Buyer']);
        $pipeline = app(PipelineManager::class)->create('Assignment pipeline', [
            ['name' => 'Open', 'probability' => 20],
        ]);
        $lead = app(LeadManager::class)->create([
            'title' => 'Seller lead',
            'contact_id' => $contact->id,
        ], $sellerMembership);
        $deal = app(DealManager::class)->create([
            'title' => 'Seller deal',
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'amount' => 25_000,
        ], $pipeline, $pipeline->stages[0], $sellerMembership, $ownerMembership);

        return [$lead, $deal];
    });
    $leadUrl = $this->tenantUrl($tenant, "/crm/leads/{$lead->id}/assignment");
    $dealUrl = $this->tenantUrl($tenant, "/crm/deals/{$deal->id}/assignment");

    $this->actingAsMember($tenant, $seller)
        ->put($leadUrl, ['owner_id' => $targetMembership->id])
        ->assertForbidden();
    $this->put($leadUrl, ['owner_id' => null])->assertForbidden();
    $this->put($dealUrl, ['owner_id' => $targetMembership->id])->assertForbidden();
    $this->put($dealUrl, ['owner_id' => null])->assertForbidden();
    $this->put($leadUrl, ['owner_id' => $sellerMembership->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->inTenant($tenant, fn () => $lead->refresh()->owner_id))->toBe($sellerMembership->id)
        ->and($this->inTenant($tenant, fn () => $deal->refresh()->owner_id))->toBe($sellerMembership->id);

    $this->startNewBrowserSession()->actingAsOwner($tenant)
        ->put($leadUrl, ['owner_id' => $targetMembership->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->put($dealUrl, ['owner_id' => $targetMembership->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->inTenant($tenant, fn () => $lead->refresh()->owner_id))->toBe($targetMembership->id)
        ->and($this->inTenant($tenant, fn () => $deal->refresh()->owner_id))->toBe($targetMembership->id);
});
