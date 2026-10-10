<?php

declare(strict_types=1);

use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Actions\QuoteManager;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\QuoteAccepted;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = $this->createTenant('sales-quotes');
    $this->owner = $this->ownerOf($this->tenant);
    $this->membership = $this->membershipOf($this->tenant, $this->owner);
    $this->entitle($this->tenant, 'crm');

    [$this->contact, $this->product] = $this->inTenant($this->tenant, fn (): array => [
        Contact::factory()->create(['first_name' => 'Saleha', 'last_name' => 'Rahman']),
        Product::query()->create([
            'type' => CatalogItemType::Service,
            'sku' => 'IMPLEMENT',
            'name' => 'Implementation',
            'description' => 'Original description',
            'unit' => 'hour',
            'currency' => 'BDT',
            'unit_price_amount' => 25_000,
            'tax_bps' => 1500,
            'is_active' => true,
        ]),
    ]);

    $this->makeQuote = function (?string $validUntil = null): Quote {
        return $this->inTenant($this->tenant, fn (): Quote => app(QuoteManager::class)->create([
            'contact_id' => $this->contact->id,
            'kind' => QuoteKind::Quotation->value,
            'title' => 'Implementation proposal',
            'currency' => 'BDT',
            'probability_percent' => 60,
            'valid_until' => $validUntil,
        ], [[
            'product_id' => $this->product->id,
            'quantity_milli' => 1500,
            'discount_amount' => 2500,
        ]], $this->membership));
    };
});

it('freezes quote lines and creates an immutable order hand-off on acceptance', function () {
    Event::fake([QuoteAccepted::class]);
    $quote = ($this->makeQuote)(now()->addDays(7)->toDateString());

    $this->inTenant($this->tenant, function () use ($quote): void {
        $this->product->update(['name' => 'Renamed later', 'unit_price_amount' => 99_999]);
        $line = $quote->lines()->sole();

        expect($line->name)->toBe('Implementation')
            ->and($line->unit_price_amount)->toBe(25_000)
            ->and($line->line_total_amount)->toBe(40_250);

        $manager = app(QuoteManager::class);
        $manager->send($quote);
        expect(fn () => $line->update(['name' => 'Tampered']))
            ->toThrow(LogicException::class, 'cannot change after the quote is sent');

        $order = $manager->accept($quote);
        $retried = $manager->accept($quote);
        $orderLine = $order->lines()->sole();
        $profile = CustomerProfile::query()->where('contact_id', $this->contact->id)->sole();

        expect($retried->id)->toBe($order->id)
            ->and(SalesOrder::query()->where('quote_id', $quote->id)->count())->toBe(1)
            ->and($order->status)->toBe(SalesOrderStatus::Confirmed)
            ->and($order->owner_id)->toBe($this->membership->id)
            ->and($orderLine->name)->toBe('Implementation')
            ->and($orderLine->line_total_amount)->toBe(40_250)
            ->and(fn () => $orderLine->update(['name' => 'Tampered']))
            ->toThrow(LogicException::class, 'immutable')
            ->and($profile->stage)->toBe(CustomerLifecycleStage::Customer)
            ->and($profile->lifetime_values)->toBe(['BDT' => 40_250]);
    });

    Event::assertDispatched(QuoteAccepted::class, fn (QuoteAccepted $event): bool => $event->tenantId() === $this->tenant->id
        && $event->payload()['quote_id'] === $quote->id
        && $event->payload()['total_amount'] === 40_250
        && $event->payload()['lines'][0]['name'] === 'Implementation'
        && $event->payload()['lines'][0]['line_total_amount'] === 40_250);
});

it('recognizes lifetime value only from the won deal when its linked quote is accepted', function () {
    $this->inTenant($this->tenant, function (): void {
        $pipeline = app(PipelineManager::class)->create('Linked sale', [
            ['name' => 'Won', 'probability' => 100, 'is_won' => true],
        ]);
        $deal = app(DealManager::class)->create([
            'title' => 'Canonical opportunity',
            'contact_id' => $this->contact->id,
            'currency' => 'BDT',
            'amount' => 75_000,
        ], $pipeline, $pipeline->stages[0], $this->membership, $this->membership);
        $manager = app(QuoteManager::class);
        $quote = $manager->create([
            'deal_id' => $deal->id,
            'contact_id' => $this->contact->id,
            'kind' => QuoteKind::Quotation->value,
            'title' => 'Linked quotation',
            'currency' => 'BDT',
            'probability_percent' => 100,
        ], [[
            'product_id' => $this->product->id,
            'quantity_milli' => 1500,
            'discount_amount' => 2500,
        ]], $this->membership);

        $manager->send($quote);
        $manager->accept($quote);

        $profile = CustomerProfile::query()->where('contact_id', $this->contact->id)->sole();

        expect($quote->refresh()->deal_id)->toBe($deal->id)
            ->and($profile->lifetime_values)->toBe(['BDT' => 75_000]);
    });
});

it('offers and accepts only deals inside the quote author record scope', function () {
    $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
        $this->owner,
        'Scoped quote author',
        null,
        ['core.contacts.view', 'crm.deals.view', 'crm.quotes.view', 'crm.quotes.manage'],
        ['core.contacts.view' => 'all', 'crm.deals.view' => 'own', 'crm.quotes.view' => 'own', 'crm.quotes.manage' => 'own'],
    ));
    $seller = $this->addMember($this->tenant, $role);
    $sellerMembership = $this->membershipOf($this->tenant, $seller);

    [$ownDeal, $foreignDeal] = $this->inTenant($this->tenant, function () use ($sellerMembership): array {
        $pipeline = app(PipelineManager::class)->create('Scoped quote deals', [
            ['name' => 'Open', 'probability' => 40],
        ]);
        $manager = app(DealManager::class);
        $attributes = [
            'contact_id' => $this->contact->id,
            'currency' => 'BDT',
            'amount' => 10_000,
        ];

        return [
            $manager->create([...$attributes, 'title' => 'Seller deal'], $pipeline, $pipeline->stages[0], $sellerMembership, $this->membership),
            $manager->create([...$attributes, 'title' => 'Owner deal'], $pipeline, $pipeline->stages[0], $this->membership, $this->membership),
        ];
    });

    $this->actingAsMember($this->tenant, $seller)
        ->get($this->tenantUrl($this->tenant, '/crm/sales/quotes'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/CRM/Pages/Sales/Quotes/Index')
            ->where('form.canSelectDeals', true)
            ->has('form.deals', 1)
            ->where('form.deals.0.id', $ownDeal->id));

    $payload = [
        'contact_id' => $this->contact->id,
        'kind' => QuoteKind::Quotation->value,
        'title' => 'Scoped linked quotation',
        'currency' => 'BDT',
        'probability_percent' => 50,
        'lines' => [[
            'item_type' => CatalogItemType::Service->value,
            'name' => 'Custom delivery',
            'unit' => 'unit',
            'quantity_milli' => 1000,
            'unit_price_amount' => 10_000,
            'discount_amount' => 0,
            'tax_bps' => 0,
        ]],
    ];

    $this->post($this->tenantUrl($this->tenant, '/crm/sales/quotes'), [...$payload, 'deal_id' => $foreignDeal->id])
        ->assertNotFound();

    $this->post($this->tenantUrl($this->tenant, '/crm/sales/quotes'), [...$payload, 'deal_id' => $ownDeal->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->inTenant($this->tenant, fn () => Quote::query()->where('title', 'Scoped linked quotation')->sole()->deal_id))
        ->toBe($ownDeal->id);
});

it('commits an expired status before refusing late acceptance', function () {
    $quote = ($this->makeQuote)(now()->addDay()->toDateString());

    $this->inTenant($this->tenant, fn () => app(QuoteManager::class)->send($quote));
    $this->travel(2)->days();

    expect(fn () => $this->inTenant($this->tenant, fn () => app(QuoteManager::class)->accept($quote)))
        ->toThrow(ValidationException::class, 'expired');

    expect($this->inTenant($this->tenant, fn () => $quote->refresh()->status))->toBe(QuoteStatus::Expired);
});

it('assigns a scoped creator and immediately lets them open their own quote', function () {
    $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
        $this->owner,
        'Own sales',
        null,
        ['core.contacts.view', 'crm.products.view', 'crm.quotes.view', 'crm.quotes.manage'],
        ['core.contacts.view' => 'all', 'crm.products.view' => 'all', 'crm.quotes.view' => 'own', 'crm.quotes.manage' => 'own'],
    ));
    $seller = $this->addMember($this->tenant, $role);

    $response = $this->actingAsMember($this->tenant, $seller)->post(
        $this->tenantUrl($this->tenant, '/crm/sales/quotes'),
        [
            'contact_id' => $this->contact->id,
            'kind' => 'quotation',
            'title' => 'Mine immediately',
            'currency' => 'BDT',
            'probability_percent' => 50,
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity_milli' => 1000,
                'discount_amount' => 0,
            ]],
        ],
    );
    $response->assertRedirect()->assertSessionHasNoErrors();

    $quote = $this->inTenant($this->tenant, fn () => Quote::query()->where('title', 'Mine immediately')->sole());
    expect($quote->owner_id)->toBe($this->membershipOf($this->tenant, $seller)->id);

    $this->get($this->tenantUrl($this->tenant, "/crm/sales/quotes/{$quote->id}"))->assertOk();
});

it('does not resolve a quotation on another workspace host', function () {
    $quote = ($this->makeQuote)();
    $other = $this->createTenant('sales-other');
    $this->entitle($other, 'crm');

    $this->assertNotFoundFromOtherTenant(
        'GET',
        "/crm/sales/quotes/{$quote->id}",
        $this->tenant,
        intruder: $other,
    );
});
