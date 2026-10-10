<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\ActivityManager;
use App\Modules\CRM\Actions\CatalogManager;
use App\Modules\CRM\Actions\ContractManager;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Actions\QuoteManager;
use App\Modules\CRM\Actions\SalesTargetManager;
use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityType;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\ContractStatus;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Attachment;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\PriceListItem;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Assert;

it('returns 404 for every foreign CRM route model before controller mutation', function () {
    $home = $this->createTenant('crm-route-isolation-home');
    $intruder = $this->createTenant('crm-route-isolation-intruder');
    $this->entitle($home, 'crm');
    $this->entitle($intruder, 'crm');
    $homeMembership = $this->membershipOf($home, $this->ownerOf($home));
    $intruderMembership = $this->membershipOf($intruder, $this->ownerOf($intruder));

    $homeRecords = $this->inTenant($home, function () use ($homeMembership): array {
        $contact = Contact::factory()->create(['first_name' => 'Home', 'last_name' => 'Buyer']);
        $pipeline = app(PipelineManager::class)->create('Home pipeline', [
            ['name' => 'Open', 'probability' => 20],
            ['name' => 'Review', 'probability' => 50],
        ]);
        $lead = app(LeadManager::class)->create([
            'title' => 'Home lead',
            'contact_id' => $contact->id,
        ], $homeMembership);
        $deal = app(DealManager::class)->create([
            'title' => 'Home deal',
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'amount' => 50_000,
        ], $pipeline, $pipeline->stages[0], $homeMembership, $homeMembership);

        $activities = collect(['edit', 'complete', 'cancel'])->mapWithKeys(fn (string $purpose): array => [
            $purpose => app(ActivityManager::class)->create([
                'type' => ActivityType::Task->value,
                'priority' => ActivityPriority::Normal->value,
                'subject' => ucfirst($purpose).' activity',
                'contact_id' => $contact->id,
            ], $homeMembership, $homeMembership),
        ]);

        $catalog = app(CatalogManager::class);
        $product = $catalog->createProduct([
            'type' => CatalogItemType::Service->value,
            'sku' => 'HOME-SERVICE',
            'name' => 'Home service',
            'unit' => 'unit',
            'currency' => 'BDT',
            'unit_price_amount' => 10_000,
            'tax_bps' => 0,
            'is_active' => true,
        ]);
        $priceList = $catalog->createPriceList(['name' => 'Home list', 'currency' => 'BDT', 'is_active' => true]);
        $otherPriceList = $catalog->createPriceList(['name' => 'Other home list', 'currency' => 'BDT', 'is_active' => true]);
        $priceListItem = $catalog->setPrice($priceList, $product, 1000, 9000);
        $deletablePriceListItem = $catalog->setPrice($priceList, $product, 2000, 8000);

        $quoteManager = app(QuoteManager::class);
        $makeQuote = fn (string $title) => $quoteManager->create([
            'contact_id' => $contact->id,
            'kind' => QuoteKind::Quotation->value,
            'title' => $title,
            'currency' => 'BDT',
            'probability_percent' => 50,
        ], [[
            'item_type' => CatalogItemType::Service->value,
            'name' => 'Service',
            'unit' => 'unit',
            'quantity_milli' => 1000,
            'unit_price_amount' => 10_000,
            'discount_amount' => 0,
            'tax_bps' => 0,
        ]], $homeMembership);
        $quotes = [
            'update' => $makeQuote('Update quote'),
            'send' => $makeQuote('Send quote'),
            'cancel' => $makeQuote('Cancel quote'),
            'reject' => $makeQuote('Reject quote'),
            'accept' => $makeQuote('Accept quote'),
        ];
        $quoteManager->send($quotes['reject']);
        $quoteManager->send($quotes['accept']);

        $order = new SalesOrder([
            'contact_id' => $contact->id,
            'number' => 'SO-ROUTE-ISOLATION',
            'status' => SalesOrderStatus::Confirmed,
            'currency' => 'BDT',
            'subtotal_amount' => 10_000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 10_000,
            'ordered_at' => now(),
        ]);
        $order->assignOwner($homeMembership)->save();

        $contractManager = app(ContractManager::class);
        $contracts = [
            'update' => $contractManager->create([
                'contact_id' => $contact->id,
                'title' => 'Update contract',
                'currency' => 'BDT',
                'value_amount' => 10_000,
                'starts_on' => '2026-10-10',
            ], $homeMembership),
            'status' => $contractManager->create([
                'contact_id' => $contact->id,
                'title' => 'Status contract',
                'currency' => 'BDT',
                'value_amount' => 10_000,
                'starts_on' => '2026-10-10',
            ], $homeMembership),
        ];
        $target = app(SalesTargetManager::class)->create([
            'name' => 'Home target',
            'currency' => 'BDT',
            'target_amount' => 100_000,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ], $homeMembership);
        $profile = CustomerProfile::query()->where('contact_id', $contact->id)->sole();

        $attachment = function (CrmEntityType $type, string $entityId, string $name) use ($homeMembership): Attachment {
            return Attachment::query()->create([
                'entity_type' => $type,
                'entity_id' => $entityId,
                'original_name' => $name,
                'path' => 'crm/route-isolation/'.$name,
                'mime_type' => 'text/plain',
                'size' => 10,
                'uploaded_by' => $homeMembership->id,
            ]);
        };

        return compact(
            'contact', 'pipeline', 'lead', 'deal', 'activities', 'product', 'priceList', 'otherPriceList',
            'priceListItem', 'deletablePriceListItem', 'quotes', 'order', 'contracts', 'target', 'profile',
        ) + [
            'leadDownload' => $attachment(CrmEntityType::Lead, $lead->id, 'lead-download.txt'),
            'leadDelete' => $attachment(CrmEntityType::Lead, $lead->id, 'lead-delete.txt'),
            'dealDownload' => $attachment(CrmEntityType::Deal, $deal->id, 'deal-download.txt'),
            'dealDelete' => $attachment(CrmEntityType::Deal, $deal->id, 'deal-delete.txt'),
            'customerDownload' => $attachment(CrmEntityType::Contact, $contact->id, 'customer-download.txt'),
            'customerDelete' => $attachment(CrmEntityType::Contact, $contact->id, 'customer-delete.txt'),
        ];
    });

    $intruderRecords = $this->inTenant($intruder, function () use ($intruderMembership): array {
        $contact = Contact::factory()->create(['first_name' => 'Intruder', 'last_name' => 'Buyer']);
        $pipeline = app(PipelineManager::class)->create('Intruder pipeline', [
            ['name' => 'Open', 'probability' => 25],
            ['name' => 'Review', 'probability' => 55],
        ]);
        $lead = app(LeadManager::class)->create([
            'title' => 'Intruder lead',
            'contact_id' => $contact->id,
        ], $intruderMembership);
        $deal = app(DealManager::class)->create([
            'title' => 'Intruder deal',
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'amount' => 25_000,
        ], $pipeline, $pipeline->stages[0], $intruderMembership, $intruderMembership);
        $product = app(CatalogManager::class)->createProduct([
            'type' => CatalogItemType::Service->value,
            'sku' => 'INTRUDER-SERVICE',
            'name' => 'Intruder service',
            'unit' => 'unit',
            'currency' => 'BDT',
            'unit_price_amount' => 5000,
            'tax_bps' => 0,
            'is_active' => true,
        ]);
        $priceList = app(CatalogManager::class)->createPriceList([
            'name' => 'Intruder list',
            'currency' => 'BDT',
            'is_active' => true,
        ]);

        $profile = new CustomerProfile([
            'contact_id' => $contact->id,
            'stage' => CustomerLifecycleStage::Prospect,
            'lifetime_values' => [],
        ]);
        $profile->assignOwner($intruderMembership)->save();

        return [
            'contact' => $contact,
            'pipeline' => $pipeline,
            'lead' => $lead,
            'deal' => $deal,
            'product' => $product,
            'priceList' => $priceList,
            'profile' => $profile,
        ];
    });

    $payload = static function (array|Closure $value): array {
        return $value instanceof Closure ? $value() : $value;
    };
    $assertIsolated = function (
        string $label,
        string $method,
        string $path,
        array|Closure $data = [],
        array|Closure|null $controlData = null,
    ) use ($home, $intruder, $payload): void {
        $foreign = $this->startNewBrowserSession()
            ->actingAsOwner($intruder)
            ->call($method, $this->tenantUrl($intruder, $path), $payload($data));
        Assert::assertSame(404, $foreign->getStatusCode(), "Cross-tenant route [{$label}] did not return 404.");

        $control = $this->startNewBrowserSession()
            ->actingAsOwner($home)
            ->call($method, $this->tenantUrl($home, $path), $payload($controlData ?? $data));
        Assert::assertNotSame(404, $control->getStatusCode(), "Home control for [{$label}] also returned 404.");
        Assert::assertLessThan(500, $control->getStatusCode(), "Home control for [{$label}] failed.");
    };

    $lead = $homeRecords['lead'];
    $deal = $homeRecords['deal'];
    $pipeline = $homeRecords['pipeline'];
    $homeContact = $homeRecords['contact'];
    $homeProduct = $homeRecords['product'];
    $homePriceList = $homeRecords['priceList'];
    $homeProfile = $homeRecords['profile'];

    // Child bindings and parent-child links are checked before the valid delete controls mutate their fixtures.
    foreach ([
        ['lead attachment child download', 'GET', "/crm/leads/{$intruderRecords['lead']->id}/attachments/{$homeRecords['leadDownload']->id}"],
        ['lead attachment child delete', 'DELETE', "/crm/leads/{$intruderRecords['lead']->id}/attachments/{$homeRecords['leadDelete']->id}"],
        ['deal attachment child download', 'GET', "/crm/deals/{$intruderRecords['deal']->id}/attachments/{$homeRecords['dealDownload']->id}"],
        ['deal attachment child delete', 'DELETE', "/crm/deals/{$intruderRecords['deal']->id}/attachments/{$homeRecords['dealDelete']->id}"],
        ['customer attachment child download', 'GET', "/crm/sales/customers/{$intruderRecords['profile']->id}/attachments/{$homeRecords['customerDownload']->id}"],
        ['customer attachment child delete', 'DELETE', "/crm/sales/customers/{$intruderRecords['profile']->id}/attachments/{$homeRecords['customerDelete']->id}"],
    ] as [$label, $method, $path]) {
        $response = $this->startNewBrowserSession()->actingAsOwner($intruder)
            ->call($method, $this->tenantUrl($intruder, $path));
        Assert::assertSame(404, $response->getStatusCode(), "Foreign nested model [{$label}] did not return 404.");
    }

    foreach ([
        ['lead mismatched attachment download', 'GET', "/crm/leads/{$lead->id}/attachments/{$homeRecords['dealDownload']->id}"],
        ['lead mismatched attachment delete', 'DELETE', "/crm/leads/{$lead->id}/attachments/{$homeRecords['dealDelete']->id}"],
        ['deal mismatched attachment download', 'GET', "/crm/deals/{$deal->id}/attachments/{$homeRecords['leadDownload']->id}"],
        ['deal mismatched attachment delete', 'DELETE', "/crm/deals/{$deal->id}/attachments/{$homeRecords['leadDelete']->id}"],
        ['customer mismatched attachment download', 'GET', "/crm/sales/customers/{$homeProfile->id}/attachments/{$homeRecords['leadDownload']->id}"],
        ['customer mismatched attachment delete', 'DELETE', "/crm/sales/customers/{$homeProfile->id}/attachments/{$homeRecords['leadDelete']->id}"],
    ] as [$label, $method, $path]) {
        $response = $this->startNewBrowserSession()->actingAsOwner($home)
            ->call($method, $this->tenantUrl($home, $path));
        Assert::assertSame(404, $response->getStatusCode(), "Mismatched nested model [{$label}] did not return 404.");
    }

    $foreignItem = $this->startNewBrowserSession()->actingAsOwner($intruder)->delete(
        $this->tenantUrl($intruder, "/crm/sales/price-lists/{$intruderRecords['priceList']->id}/items/{$homeRecords['priceListItem']->id}"),
    );
    $foreignItem->assertNotFound();
    $mismatchedItem = $this->startNewBrowserSession()->actingAsOwner($home)->delete(
        $this->tenantUrl($home, "/crm/sales/price-lists/{$homeRecords['otherPriceList']->id}/items/{$homeRecords['priceListItem']->id}"),
    );
    $mismatchedItem->assertNotFound();
    $this->startNewBrowserSession()->actingAsOwner($home)
        ->post($this->tenantUrl($home, "/crm/sales/price-lists/{$homePriceList->id}/items"), [
            'product_id' => $intruderRecords['product']->id,
            'minimum_quantity_milli' => 3000,
            'unit_price_amount' => 7000,
        ])->assertNotFound();
    $this->startNewBrowserSession()->actingAsOwner($home)
        ->post($this->tenantUrl($home, "/crm/deals/{$deal->id}/stage"), [
            'stage_id' => $intruderRecords['pipeline']->stages[1]->id,
        ])->assertNotFound();

    $assertIsolated('lead show', 'GET', "/crm/leads/{$lead->id}");
    $assertIsolated('lead update', 'PUT', "/crm/leads/{$lead->id}", [
        'title' => 'Home lead revised', 'contact_id' => $homeContact->id, 'status' => 'new',
    ]);
    $assertIsolated('lead note', 'POST', "/crm/leads/{$lead->id}/notes", ['body' => 'Home note']);
    $assertIsolated('lead tags', 'PUT', "/crm/leads/{$lead->id}/tags", ['tags' => []]);
    $assertIsolated('lead custom values', 'PUT', "/crm/leads/{$lead->id}/custom-values", ['values' => []]);
    $assertIsolated(
        'lead attachment upload',
        'POST',
        "/crm/leads/{$lead->id}/attachments",
        static fn (): array => ['file' => UploadedFile::fake()->create('lead.pdf', 1, 'application/pdf')],
        [],
    );
    $assertIsolated('lead attachment download', 'GET', "/crm/leads/{$lead->id}/attachments/{$homeRecords['leadDownload']->id}");
    $assertIsolated('lead attachment delete', 'DELETE', "/crm/leads/{$lead->id}/attachments/{$homeRecords['leadDelete']->id}");
    $assertIsolated('lead qualify', 'POST', "/crm/leads/{$lead->id}/qualify", ['score' => 70, 'notes' => 'Qualified']);
    $assertIsolated('lead assignment', 'PUT', "/crm/leads/{$lead->id}/assignment", ['owner_id' => $homeMembership->id]);

    $assertIsolated('deal show', 'GET', "/crm/deals/{$deal->id}");
    $assertIsolated('deal update', 'PUT', "/crm/deals/{$deal->id}", [
        'title' => 'Home deal revised', 'contact_id' => $homeContact->id, 'currency' => 'BDT', 'amount' => 55_000,
    ]);
    $assertIsolated('deal stage', 'POST', "/crm/deals/{$deal->id}/stage", ['stage_id' => $pipeline->stages[1]->id]);
    $assertIsolated('deal note', 'POST', "/crm/deals/{$deal->id}/notes", ['body' => 'Home deal note']);
    $assertIsolated('deal tags', 'PUT', "/crm/deals/{$deal->id}/tags", ['tags' => []]);
    $assertIsolated('deal custom values', 'PUT', "/crm/deals/{$deal->id}/custom-values", ['values' => []]);
    $assertIsolated(
        'deal attachment upload',
        'POST',
        "/crm/deals/{$deal->id}/attachments",
        static fn (): array => ['file' => UploadedFile::fake()->create('deal.pdf', 1, 'application/pdf')],
        [],
    );
    $assertIsolated('deal attachment download', 'GET', "/crm/deals/{$deal->id}/attachments/{$homeRecords['dealDownload']->id}");
    $assertIsolated('deal attachment delete', 'DELETE', "/crm/deals/{$deal->id}/attachments/{$homeRecords['dealDelete']->id}");
    $assertIsolated('deal assignment', 'PUT', "/crm/deals/{$deal->id}/assignment", ['owner_id' => $homeMembership->id]);

    $assertIsolated('activity update', 'PUT', "/crm/activities/{$homeRecords['activities']['edit']->id}", [
        'type' => ActivityType::Call->value,
        'priority' => ActivityPriority::High->value,
        'subject' => 'Updated activity',
        'contact_id' => $homeContact->id,
    ]);
    $assertIsolated('activity complete', 'POST', "/crm/activities/{$homeRecords['activities']['complete']->id}/complete");
    $assertIsolated('activity cancel', 'POST', "/crm/activities/{$homeRecords['activities']['cancel']->id}/cancel");
    $assertIsolated('pipeline update', 'PUT', "/crm/settings/pipelines/{$pipeline->id}", [
        'name' => 'Home pipeline revised', 'active' => true, 'default' => false,
    ]);
    $assertIsolated('pipeline stage create', 'POST', "/crm/settings/pipelines/{$pipeline->id}/stages", [
        'name' => 'Negotiation', 'probability' => 75,
    ]);

    $assertIsolated('product update', 'PUT', "/crm/sales/products/{$homeProduct->id}", [
        'type' => CatalogItemType::Service->value,
        'sku' => 'HOME-SERVICE',
        'name' => 'Home service revised',
        'unit' => 'unit',
        'currency' => 'BDT',
        'unit_price_amount' => 11_000,
        'tax_bps' => 0,
        'is_active' => true,
    ]);
    $assertIsolated('price list update', 'PUT', "/crm/sales/price-lists/{$homePriceList->id}", [
        'name' => 'Home list revised', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $assertIsolated('price list item create', 'POST', "/crm/sales/price-lists/{$homePriceList->id}/items", [
        'product_id' => $homeProduct->id, 'minimum_quantity_milli' => 3000, 'unit_price_amount' => 7000,
    ]);
    $assertIsolated(
        'price list item delete',
        'DELETE',
        "/crm/sales/price-lists/{$homePriceList->id}/items/{$homeRecords['deletablePriceListItem']->id}",
    );

    $quotePayload = [
        'contact_id' => $homeContact->id,
        'kind' => QuoteKind::Quotation->value,
        'title' => 'Home quote revised',
        'currency' => 'BDT',
        'probability_percent' => 60,
        'lines' => [[
            'item_type' => CatalogItemType::Service->value,
            'name' => 'Revised service',
            'unit' => 'unit',
            'quantity_milli' => 1000,
            'unit_price_amount' => 12_000,
            'discount_amount' => 0,
            'tax_bps' => 0,
        ]],
    ];
    $assertIsolated('quote show', 'GET', "/crm/sales/quotes/{$homeRecords['quotes']['update']->id}");
    $assertIsolated('quote update', 'PUT', "/crm/sales/quotes/{$homeRecords['quotes']['update']->id}", $quotePayload);
    $assertIsolated('quote send', 'POST', "/crm/sales/quotes/{$homeRecords['quotes']['send']->id}/send");
    $assertIsolated('quote reject', 'POST', "/crm/sales/quotes/{$homeRecords['quotes']['reject']->id}/reject");
    $assertIsolated('quote cancel', 'POST', "/crm/sales/quotes/{$homeRecords['quotes']['cancel']->id}/cancel");
    $assertIsolated('quote accept', 'POST', "/crm/sales/quotes/{$homeRecords['quotes']['accept']->id}/accept");

    $assertIsolated('sales order show', 'GET', "/crm/sales/orders/{$homeRecords['order']->id}");
    $assertIsolated('sales order status', 'PATCH', "/crm/sales/orders/{$homeRecords['order']->id}/status", [
        'status' => SalesOrderStatus::Fulfilled->value,
    ]);
    $assertIsolated('contract update', 'PUT', "/crm/sales/contracts/{$homeRecords['contracts']['update']->id}", [
        'contact_id' => $homeContact->id,
        'title' => 'Home contract revised',
        'currency' => 'BDT',
        'value_amount' => 12_000,
        'starts_on' => '2026-10-10',
    ]);
    $assertIsolated('contract status', 'PATCH', "/crm/sales/contracts/{$homeRecords['contracts']['status']->id}/status", [
        'status' => ContractStatus::Active->value,
    ]);
    $assertIsolated('sales target update', 'PUT', "/crm/sales/targets/{$homeRecords['target']->id}", [
        'name' => 'Home target revised',
        'currency' => 'BDT',
        'target_amount' => 120_000,
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);

    $assertIsolated('customer show', 'GET', "/crm/sales/customers/{$homeProfile->id}");
    $assertIsolated('customer update', 'PATCH', "/crm/sales/customers/{$homeProfile->id}", [
        'stage' => CustomerLifecycleStage::Prospect->value,
    ]);
    $assertIsolated('customer note', 'POST', "/crm/sales/customers/{$homeProfile->id}/notes", ['body' => 'Home customer note']);
    $assertIsolated('customer tags', 'PUT', "/crm/sales/customers/{$homeProfile->id}/tags", ['tags' => []]);
    $assertIsolated('customer custom values', 'PUT', "/crm/sales/customers/{$homeProfile->id}/custom-values", ['values' => []]);
    $assertIsolated(
        'customer attachment upload',
        'POST',
        "/crm/sales/customers/{$homeProfile->id}/attachments",
        static fn (): array => ['file' => UploadedFile::fake()->create('customer.pdf', 1, 'application/pdf')],
        [],
    );
    $assertIsolated('customer attachment download', 'GET', "/crm/sales/customers/{$homeProfile->id}/attachments/{$homeRecords['customerDownload']->id}");
    $assertIsolated('customer attachment delete', 'DELETE', "/crm/sales/customers/{$homeProfile->id}/attachments/{$homeRecords['customerDelete']->id}");

    expect(PriceListItem::withoutGlobalScopes()->find($homeRecords['priceListItem']->id))->not->toBeNull();
});
