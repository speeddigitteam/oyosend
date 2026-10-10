<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Actions\CatalogManager;
use App\Modules\CRM\Models\PriceList;
use App\Modules\CRM\Models\PriceListItem;
use App\Modules\CRM\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PriceListController
{
    public function __construct(
        private readonly CatalogManager $catalog,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($filters['search'] ?? ''));

        $priceLists = PriceList::query()
            ->with(['items' => fn ($query) => $query->with('product:id,name,sku,unit')->orderBy('minimum_quantity_milli')])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', Like::contains($search)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PriceList $list): array => [
                'id' => $list->id,
                'name' => $list->name,
                'currency' => $list->currency,
                'startsOn' => $list->starts_on?->toDateString(),
                'endsOn' => $list->ends_on?->toDateString(),
                'active' => $list->is_active,
                'items' => $list->items->map(fn (PriceListItem $item): array => [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'unit' => $item->product->unit,
                    ],
                    'minimumQuantityMilli' => $item->minimum_quantity_milli,
                    'unitPriceAmount' => $item->unit_price_amount,
                ])->all(),
            ]);

        return Inertia::render('Modules/CRM/Pages/Sales/PriceLists/Index', [
            'priceLists' => $priceLists,
            'filters' => ['search' => $search],
            'products' => Product::query()->where('is_active', true)->orderBy('name')->limit(500)
                ->get(['id', 'name', 'sku', 'unit', 'currency'])
                ->map(fn (Product $product): array => $product->only(['id', 'name', 'sku', 'unit', 'currency']))
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $list = $this->catalog->createPriceList($this->validated($request));
        Toast::success(__('Price list ":name" added.', ['name' => $list->name]));

        return back();
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->catalog->updatePriceList($priceList, $this->validated($request, $priceList));
        Toast::success(__('Price list ":name" saved.', ['name' => $priceList->name]));

        return back();
    }

    public function storeItem(Request $request, PriceList $priceList): RedirectResponse
    {
        $input = $request->validate([
            'product_id' => ['required', 'string', 'size:26'],
            'minimum_quantity_milli' => ['required', 'integer', 'between:1,1000000'],
            'unit_price_amount' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ]);
        $product = Product::query()->findOrFail($input['product_id']);
        $this->catalog->setPrice(
            $priceList,
            $product,
            (int) $input['minimum_quantity_milli'],
            (int) $input['unit_price_amount'],
        );
        Toast::success(__('Price-list item saved.'));

        return back();
    }

    public function destroyItem(PriceList $priceList, PriceListItem $priceListItem): RedirectResponse
    {
        $this->catalog->removePrice($priceList, $priceListItem);
        Toast::success(__('Price-list item removed.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PriceList $priceList = null): array
    {
        $input = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('crm_price_lists', 'name')
                    ->where('tenant_id', $this->context->requireId())
                    ->ignore($priceList?->id),
            ],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['required', 'boolean'],
        ]);
        $input['currency'] = strtoupper((string) $input['currency']);

        return $input;
    }
}
