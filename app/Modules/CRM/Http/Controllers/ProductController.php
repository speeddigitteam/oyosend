<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Actions\CatalogManager;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController
{
    public function __construct(
        private readonly CatalogManager $catalog,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(CatalogItemType::class)],
            'active' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $products = Product::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', Like::contains($search))
                ->orWhere('sku', 'like', Like::contains($search))))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product): array => $this->present($product));

        return Inertia::render('Modules/CRM/Pages/Sales/Products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $search,
                'type' => $filters['type'] ?? null,
                'active' => $filters['active'] ?? null,
            ],
            'types' => array_map(static fn (CatalogItemType $type): string => $type->value, CatalogItemType::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = $this->catalog->createProduct($this->validated($request));
        Toast::success(__('Catalog item ":name" added.', ['name' => $product->name]));

        return back();
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->catalog->updateProduct($product, $this->validated($request, $product));
        Toast::success(__('Catalog item ":name" saved.', ['name' => $product->name]));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $input = $request->validate([
            'type' => ['required', Rule::enum(CatalogItemType::class)],
            'sku' => [
                'nullable', 'string', 'max:80',
                Rule::unique('crm_products', 'sku')
                    ->where('tenant_id', $this->context->requireId())
                    ->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', 'string', 'max:40'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'unit_price_amount' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'tax_bps' => ['required', 'integer', 'between:0,10000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $input['currency'] = strtoupper((string) $input['currency']);
        $input['sku'] = filled($input['sku'] ?? null) ? strtoupper(trim((string) $input['sku'])) : null;

        return Arr::only($input, [
            'type', 'sku', 'name', 'description', 'unit', 'currency', 'unit_price_amount', 'tax_bps', 'is_active',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Product $product): array
    {
        return [
            'id' => $product->id,
            'type' => $product->type->value,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'unit' => $product->unit,
            'currency' => $product->currency,
            'unitPriceAmount' => $product->unit_price_amount,
            'taxBps' => $product->tax_bps,
            'active' => $product->is_active,
            'createdAt' => $product->created_at->toIso8601String(),
        ];
    }
}
