<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\ContractStatus;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Contract;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\PriceList;
use App\Modules\CRM\Models\PriceListItem;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\QuoteLine;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderLine;
use App\Modules\CRM\Models\SalesTarget;

dataset('sales tenant models', [
    'product' => [Product::class, fn () => Product::query()->create([
        'type' => CatalogItemType::Product, 'name' => 'Item', 'unit' => 'unit', 'currency' => 'BDT',
        'unit_price_amount' => 100, 'tax_bps' => 0, 'is_active' => true,
    ])],
    'price list' => [PriceList::class, fn () => PriceList::query()->create([
        'name' => 'Retail', 'currency' => 'BDT', 'is_active' => true,
    ])],
    'price-list item' => [PriceListItem::class, function () {
        $product = Product::query()->create([
            'type' => CatalogItemType::Product, 'name' => 'Item', 'unit' => 'unit', 'currency' => 'BDT',
            'unit_price_amount' => 100, 'tax_bps' => 0, 'is_active' => true,
        ]);
        $list = PriceList::query()->create(['name' => 'Retail', 'currency' => 'BDT', 'is_active' => true]);

        return PriceListItem::query()->create([
            'price_list_id' => $list->id, 'product_id' => $product->id,
            'minimum_quantity_milli' => 1000, 'unit_price_amount' => 90,
        ]);
    }],
    'quote' => [Quote::class, fn () => Quote::query()->create([
        'number' => 'QT-ONE', 'kind' => QuoteKind::Quotation, 'title' => 'Quote', 'status' => QuoteStatus::Draft,
        'currency' => 'BDT', 'probability_percent' => 50, 'subtotal_amount' => 100, 'discount_amount' => 0,
        'tax_amount' => 0, 'total_amount' => 100,
    ])],
    'quote line' => [QuoteLine::class, function () {
        $quote = Quote::query()->create([
            'number' => 'QT-LINE', 'kind' => QuoteKind::Quotation, 'title' => 'Quote', 'status' => QuoteStatus::Draft,
            'currency' => 'BDT', 'probability_percent' => 50, 'subtotal_amount' => 100, 'discount_amount' => 0,
            'tax_amount' => 0, 'total_amount' => 100,
        ]);

        return QuoteLine::query()->create([
            'quote_id' => $quote->id, 'position' => 1, 'item_type' => CatalogItemType::Service, 'name' => 'Work',
            'unit' => 'hour', 'quantity_milli' => 1000, 'currency' => 'BDT', 'unit_price_amount' => 100,
            'discount_amount' => 0, 'tax_bps' => 0, 'tax_amount' => 0, 'line_total_amount' => 100,
        ]);
    }],
    'sales order' => [SalesOrder::class, fn () => SalesOrder::query()->create([
        'number' => 'SO-ONE', 'status' => SalesOrderStatus::Confirmed, 'currency' => 'BDT', 'subtotal_amount' => 100,
        'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 100, 'ordered_at' => now(),
    ])],
    'sales-order line' => [SalesOrderLine::class, function () {
        $order = SalesOrder::query()->create([
            'number' => 'SO-LINE', 'status' => SalesOrderStatus::Confirmed, 'currency' => 'BDT', 'subtotal_amount' => 100,
            'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 100, 'ordered_at' => now(),
        ]);

        return SalesOrderLine::query()->create([
            'sales_order_id' => $order->id, 'position' => 1, 'item_type' => CatalogItemType::Service, 'name' => 'Work',
            'unit' => 'hour', 'quantity_milli' => 1000, 'currency' => 'BDT', 'unit_price_amount' => 100,
            'discount_amount' => 0, 'tax_bps' => 0, 'tax_amount' => 0, 'line_total_amount' => 100,
        ]);
    }],
    'contract' => [Contract::class, fn () => Contract::query()->create([
        'number' => 'CT-ONE', 'title' => 'Contract', 'status' => ContractStatus::Draft, 'currency' => 'BDT',
        'value_amount' => 100, 'starts_on' => today(),
    ])],
    'sales target' => [SalesTarget::class, fn () => SalesTarget::query()->create([
        'name' => 'Monthly', 'currency' => 'BDT', 'target_amount' => 100,
        'period_start' => today(), 'period_end' => today()->addMonth(),
    ])],
    'customer profile' => [CustomerProfile::class, fn () => CustomerProfile::query()->create([
        'contact_id' => Contact::factory()->create()->id,
        'stage' => CustomerLifecycleStage::Prospect,
        'lifetime_values' => [],
    ])],
]);

it('isolates every sales model by tenant', function (string $model, Closure $make): void {
    $this->assertTenantIsolated($model, fn () => $make());
})->with('sales tenant models');
