<?php

declare(strict_types=1);

use App\Core\Access\RoleManager;
use App\Core\Contacts\ContactMerger;
use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\CatalogManager;
use App\Modules\CRM\Actions\ContractManager;
use App\Modules\CRM\Actions\CrmContentManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Automation\Actions\CreateFollowUpAction;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\AutomationActionReceipt;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesTarget;
use Illuminate\Validation\ValidationException;

it('keeps lead notes and timeline entries off a customer page for a viewer who cannot see that lead', function () {
    $tenant = $this->createTenant('crm-fix-customer-leak');
    $this->entitle($tenant, 'crm');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $ownerUser,
        'Customer-only readers',
        null,
        ['crm.customers.view'],
        ['crm.customers.view' => 'all'],
    ));
    $reader = $this->addMember($tenant, $role);

    $profile = $this->inTenant($tenant, function () use ($owner): CustomerProfile {
        $contact = Contact::factory()->create(['first_name' => 'Shared', 'last_name' => 'Customer']);
        $profile = CustomerProfile::query()->create(['contact_id' => $contact->id, 'stage' => CustomerLifecycleStage::Customer]);
        $lead = app(LeadManager::class)->create(['title' => 'Private lead', 'contact_id' => $contact->id], $owner);
        $content = app(CrmContentManager::class);
        $content->addNote('PRIVATE lead note', ['lead_id' => $lead->id, 'contact_id' => $contact->id], $owner);
        $content->addNote('Customer-level note', ['contact_id' => $contact->id], $owner);

        return $profile;
    });

    $this->actingAsMember($tenant, $reader)
        ->get($this->tenantUrl($tenant, "/crm/sales/customers/{$profile->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('content.notes', 1)
            ->where('content.notes.0.body', 'Customer-level note'));

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, "/crm/sales/customers/{$profile->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('content.notes', 2));
});

it('lets a customer outrank an inactive or churned stage when contacts merge', function () {
    $tenant = $this->createTenant('crm-fix-merge-stage');
    $this->entitle($tenant, 'crm');

    $this->inTenant($tenant, function (): void {
        $canonical = Contact::factory()->create();
        $duplicate = Contact::factory()->create();
        CustomerProfile::query()->create(['contact_id' => $canonical->id, 'stage' => CustomerLifecycleStage::Churned]);
        CustomerProfile::query()->create(['contact_id' => $duplicate->id, 'stage' => CustomerLifecycleStage::Customer]);

        app(ContactMerger::class)->merge($canonical, $duplicate, []);

        expect(CustomerProfile::query()->where('contact_id', $canonical->id)->sole()->stage)->toBe(CustomerLifecycleStage::Customer);
    });
});

it('stores one receipt for a follow-up action and replays it on retry', function () {
    $tenant = $this->createTenant('crm-fix-followup');
    $this->entitle($tenant, 'crm');

    $this->inTenant($tenant, function (): void {
        $action = app(CreateFollowUpAction::class);
        $input = ['subject' => 'Call back', 'due_at' => now()->addDay()->toIso8601String()];

        $first = $action->execute($input, 'follow-up-1');
        $second = $action->execute($input, 'follow-up-1');

        expect($second)->toBe($first)
            ->and(Activity::query()->count())->toBe(1)
            ->and(AutomationActionReceipt::query()->where('action', CreateFollowUpAction::key())->where('idempotency_key', 'follow-up-1')->count())->toBe(1);
    });
});

it('refuses to change an order-linked contract value and a price list currency that has items', function () {
    $tenant = $this->createTenant('crm-fix-contract-currency');
    $this->entitle($tenant, 'crm');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $contact = Contact::factory()->create();
        $order = new SalesOrder([
            'number' => 'SO-LINK',
            'status' => SalesOrderStatus::Confirmed,
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'subtotal_amount' => 20_000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 20_000,
            'ordered_at' => now(),
        ]);
        $order->assignOwner($owner)->save();
        $contracts = app(ContractManager::class);
        $contract = $contracts->create(['sales_order_id' => $order->id, 'title' => 'Linked', 'starts_on' => '2026-10-01'], $owner);

        expect($contract->value_amount)->toBe(20_000)
            ->and(fn () => $contracts->update($contract, ['contact_id' => $contact->id, 'currency' => 'BDT', 'value_amount' => 1, 'title' => 'Linked', 'starts_on' => '2026-10-01']))
            ->toThrow(ValidationException::class)
            ->and(fn () => $contracts->update($contract, ['contact_id' => $contact->id, 'currency' => 'USD', 'value_amount' => 20_000, 'title' => 'Linked', 'starts_on' => '2026-10-01']))
            ->toThrow(ValidationException::class);

        expect($contracts->update($contract, ['contact_id' => $contact->id, 'currency' => 'BDT', 'value_amount' => 20_000, 'title' => 'Renamed', 'starts_on' => '2026-10-01'])->title)->toBe('Renamed');

        $catalog = app(CatalogManager::class);
        $list = $catalog->createPriceList(['name' => 'Empty list', 'currency' => 'BDT', 'is_active' => true]);

        expect($catalog->updatePriceList($list, ['currency' => 'USD'])->currency)->toBe('USD');

        $product = Product::query()->create(['type' => CatalogItemType::Service, 'sku' => 'SKU-1', 'name' => 'Thing', 'unit' => 'hour', 'currency' => 'USD', 'unit_price_amount' => 100, 'tax_bps' => 0, 'is_active' => true]);
        $catalog->setPrice($list, $product, 1000, 90);

        expect(fn () => $catalog->updatePriceList($list->refresh(), ['currency' => 'BDT']))->toThrow(ValidationException::class);
    });
});

it('lets a sales target name an owner, department, branch or the whole company', function () {
    $tenant = $this->createTenant('crm-fix-target-scope');
    $this->entitle($tenant, 'crm');
    $ownerUser = $this->ownerOf($tenant);
    $role = $this->createRole($tenant, 'Target managers', ['crm.targets.view', 'crm.targets.manage']);
    $manager = $this->addMember($tenant, $role);
    $managerMembership = $this->membershipOf($tenant, $manager);
    $base = ['name' => 'October', 'currency' => 'BDT', 'target_amount' => 100_000, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'];

    $this->actingAsMember($tenant, $manager)
        ->post($this->tenantUrl($tenant, '/crm/sales/targets'), [...$base, 'name' => 'Company', 'scope' => 'company'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post($this->tenantUrl($tenant, '/crm/sales/targets'), [...$base, 'name' => 'Default'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post($this->tenantUrl($tenant, '/crm/sales/targets'), [...$base, 'name' => 'Branch', 'scope' => 'branch'])
        ->assertSessionHasErrors('branch_id');

    $this->inTenant($tenant, function () use ($managerMembership): void {
        $company = SalesTarget::query()->where('name', 'Company')->sole();
        $default = SalesTarget::query()->where('name', 'Default')->sole();

        expect($company->owner_id)->toBeNull()
            ->and($company->department_id)->toBeNull()
            ->and($company->branch_id)->toBeNull()
            ->and($default->owner_id)->toBe($managerMembership->id);
    });
});
