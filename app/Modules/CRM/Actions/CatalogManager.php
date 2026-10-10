<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\CRM\Models\PriceList;
use App\Modules\CRM\Models\PriceListItem;
use App\Modules\CRM\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function createProduct(array $attributes): Product
    {
        return DB::transaction(fn (): Product => Product::query()->create($attributes));
    }

    /** @param array<string, mixed> $attributes */
    public function updateProduct(Product $product, array $attributes): Product
    {
        return DB::transaction(function () use ($product, $attributes): Product {
            $product->fill($attributes);

            if ($product->isDirty()) {
                $product->save();
            }

            return $product;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createPriceList(array $attributes): PriceList
    {
        $this->assertDateRange($attributes);

        return DB::transaction(fn (): PriceList => PriceList::query()->create($attributes));
    }

    /** @param array<string, mixed> $attributes */
    public function updatePriceList(PriceList $priceList, array $attributes): PriceList
    {
        $this->assertDateRange($attributes, $priceList);

        return DB::transaction(function () use ($priceList, $attributes): PriceList {
            $priceList->fill($attributes);

            if ($priceList->isDirty()) {
                $priceList->save();
            }

            return $priceList;
        });
    }

    public function setPrice(PriceList $priceList, Product $product, int $quantityMilli, int $amount): PriceListItem
    {
        if ($quantityMilli < 1 || $quantityMilli > 1_000_000 || $amount < 0 || $amount > 1_000_000_000_000) {
            throw ValidationException::withMessages(['unit_price_amount' => __('The price-list item is invalid.')]);
        }

        return DB::transaction(function () use ($priceList, $product, $quantityMilli, $amount): PriceListItem {
            $item = PriceListItem::query()->updateOrCreate(
                [
                    'price_list_id' => $priceList->id,
                    'product_id' => $product->id,
                    'minimum_quantity_milli' => $quantityMilli,
                ],
                ['unit_price_amount' => $amount],
            );

            $this->audit->record('crm.price_list.item_saved', $priceList, [
                'item_id' => $item->id,
                'product_id' => $product->id,
                'minimum_quantity_milli' => $quantityMilli,
                'unit_price_amount' => $amount,
                'currency' => $priceList->currency,
            ]);

            return $item;
        });
    }

    public function removePrice(PriceList $priceList, PriceListItem $item): void
    {
        abort_unless($item->price_list_id === $priceList->id, 404);

        DB::transaction(function () use ($priceList, $item): void {
            $itemId = $item->id;
            $item->delete();
            $this->audit->record('crm.price_list.item_removed', $priceList, ['item_id' => $itemId]);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function assertDateRange(array $attributes, ?PriceList $priceList = null): void
    {
        $starts = $attributes['starts_on'] ?? $priceList?->starts_on?->toDateString();
        $ends = $attributes['ends_on'] ?? $priceList?->ends_on?->toDateString();

        if ($starts !== null && $ends !== null && (string) $ends < (string) $starts) {
            throw ValidationException::withMessages(['ends_on' => __('The end date must be on or after the start date.')]);
        }
    }
}
