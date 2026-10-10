<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Company;
use App\Modules\CRM\Actions\CatalogManager;
use App\Modules\CRM\Actions\QuoteManager;
use App\Modules\CRM\Actions\SalesMath;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Models\PriceList;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Quote;

it('calculates fractional quantities, discounts and tax with integer arithmetic', function () {
    expect(SalesMath::line(999, 1500, 99, 1500))->toBe([
        'gross_amount' => 1499,
        'discount_amount' => 99,
        'tax_amount' => 210,
        'total_amount' => 1610,
    ]);
});

it('isolates products and price lists between workspaces', function () {
    $this->assertTenantIsolated(Product::class, fn () => Product::query()->create([
        'type' => CatalogItemType::Product,
        'sku' => 'SKU-'.fake()->unique()->numerify('####'),
        'name' => fake()->unique()->words(2, true),
        'unit' => 'unit',
        'currency' => 'BDT',
        'unit_price_amount' => 10_000,
        'tax_bps' => 0,
        'is_active' => true,
    ]));

    $this->assertTenantIsolated(PriceList::class, fn () => PriceList::query()->create([
        'name' => fake()->unique()->words(2, true),
        'currency' => 'BDT',
        'is_active' => true,
    ]));
});

it('uses the best matching price-list tier and snapshots the result', function () {
    $tenant = $this->createTenant('sales-catalog');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $quote = $this->inTenant($tenant, function () use ($owner): Quote {
        $product = Product::query()->create([
            'type' => CatalogItemType::Service,
            'sku' => 'CONSULT',
            'name' => 'Consulting',
            'unit' => 'hour',
            'currency' => 'BDT',
            'unit_price_amount' => 1000,
            'tax_bps' => 1000,
            'is_active' => true,
        ]);
        $catalog = app(CatalogManager::class);
        $list = $catalog->createPriceList(['name' => 'Volume', 'currency' => 'BDT', 'is_active' => true]);
        $catalog->setPrice($list, $product, 1000, 900);
        $catalog->setPrice($list, $product, 5000, 800);

        return app(QuoteManager::class)->create([
            'company_id' => Company::factory()->create()->id,
            'price_list_id' => $list->id,
            'kind' => QuoteKind::Quotation->value,
            'title' => 'Volume quote',
            'currency' => 'BDT',
            'probability_percent' => 50,
        ], [[
            'product_id' => $product->id,
            'quantity_milli' => 6000,
            'unit_price_amount' => 123_456,
            'discount_amount' => 0,
        ]], $owner);
    });

    $line = $this->inTenant($tenant, fn () => $quote->lines()->sole());

    expect($line->unit_price_amount)->toBe(800)
        ->and($line->quantity_milli)->toBe(6000)
        ->and($line->tax_amount)->toBe(480)
        ->and($line->line_total_amount)->toBe(5280);
});
