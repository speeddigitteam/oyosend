<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\CRM\Actions\QuoteManager;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\PriceList;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\QuoteLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class QuoteController
{
    public const string VIEW = 'crm.quotes.view';

    public const string MANAGE = 'crm.quotes.manage';

    public const string ACCEPT = 'crm.quotes.accept';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly QuoteManager $quotes,
        private readonly CrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(QuoteStatus::class)],
            'kind' => ['nullable', Rule::enum(QuoteKind::class)],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $quotes = $this->records->constrain(Quote::query(), $actor, self::VIEW)
            ->with(['deal:id,title', 'contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('number', 'like', Like::contains($search))
                ->orWhere('title', 'like', Like::contains($search))))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Quote $quote): array => $this->summary($quote, $actor));

        return Inertia::render('Modules/CRM/Pages/Sales/Quotes/Index', [
            'quotes' => $quotes,
            'filters' => [
                'search' => $search,
                'status' => $filters['status'] ?? null,
                'kind' => $filters['kind'] ?? null,
            ],
            'statuses' => array_map(static fn (QuoteStatus $status): string => $status->value, QuoteStatus::cases()),
            'kinds' => array_map(static fn (QuoteKind $kind): string => $kind->value, QuoteKind::cases()),
            'form' => $actor->can(self::MANAGE) ? $this->formOptions($actor) : $this->emptyFormOptions(),
            'canCreate' => $actor->can(self::MANAGE),
        ]);
    }

    public function show(Request $request, Quote $quote): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::VIEW, $quote);
        $quote->load(['lines', 'deal:id,title', 'contact:id,first_name,last_name,email', 'company:id,name,email', 'owner.user:id,name', 'salesOrder:id,quote_id,number,status']);

        $summary = $this->summary($quote, $actor);

        return Inertia::render('Modules/CRM/Pages/Sales/Quotes/Show', [
            'quote' => [
                ...$summary,
                'priceListId' => $quote->price_list_id,
                'probabilityPercent' => $quote->probability_percent,
                'terms' => $quote->terms,
                'notes' => $quote->notes,
                'sentAt' => $quote->sent_at?->toIso8601String(),
                'acceptedAt' => $quote->accepted_at?->toIso8601String(),
                'rejectedAt' => $quote->rejected_at?->toIso8601String(),
                'salesOrder' => $quote->salesOrder?->only(['id', 'number', 'status']),
                'lines' => $quote->lines->map(fn (QuoteLine $line): array => [
                    'id' => $line->id,
                    'productId' => $line->product_id,
                    'position' => $line->position,
                    'itemType' => $line->item_type->value,
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'unit' => $line->unit,
                    'description' => $line->description,
                    'quantityMilli' => $line->quantity_milli,
                    'currency' => $line->currency,
                    'unitPriceAmount' => $line->unit_price_amount,
                    'discountAmount' => $line->discount_amount,
                    'taxBps' => $line->tax_bps,
                    'taxAmount' => $line->tax_amount,
                    'lineTotalAmount' => $line->line_total_amount,
                ])->all(),
            ],
            'form' => $summary['editable'] ? $this->formOptions($actor, $quote) : $this->emptyFormOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        [$attributes, $lines] = $this->validated($request, $actor);
        $quote = $this->quotes->create($attributes, $lines, $this->support->membership($actor));
        Toast::success(__('Quotation ":number" created.', ['number' => $quote->number]));

        return redirect()->route('crm.sales.quotes.show', $quote);
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $quote);
        [$attributes, $lines] = $this->validated($request, $actor);
        $this->quotes->update($quote, $attributes, $lines);
        Toast::success(__('Quotation ":number" saved.', ['number' => $quote->number]));

        return back();
    }

    public function send(Request $request, Quote $quote): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $quote);
        $this->quotes->send($quote);
        Toast::success(__('Quotation sent.'));

        return back();
    }

    public function accept(Request $request, Quote $quote): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::ACCEPT, $quote);
        $order = $this->quotes->accept($quote);
        Toast::success(__('Quotation accepted and sales order created.'));

        return redirect()->route('crm.sales.orders.show', $order);
    }

    public function reject(Request $request, Quote $quote): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $quote);
        $this->quotes->reject($quote);
        Toast::success(__('Quotation rejected.'));

        return back();
    }

    public function cancel(Request $request, Quote $quote): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $quote);
        $this->quotes->cancel($quote);
        Toast::success(__('Quotation canceled.'));

        return back();
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validated(Request $request, User $actor): array
    {
        $input = $request->validate([
            'deal_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'contact_id' => ['nullable', 'string', 'size:26', 'required_without:company_id'],
            'company_id' => ['nullable', 'string', 'size:26', 'required_without:contact_id'],
            'price_list_id' => ['nullable', 'string', 'size:26'],
            'kind' => ['required', Rule::enum(QuoteKind::class)],
            'title' => ['required', 'string', 'max:200'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'probability_percent' => ['required', 'integer', 'between:0,100'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['nullable', 'string', 'size:26'],
            'lines.*.item_type' => ['nullable', Rule::enum(CatalogItemType::class)],
            'lines.*.name' => ['nullable', 'string', 'max:180'],
            'lines.*.unit' => ['nullable', 'string', 'max:40'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:1,1000000'],
            'lines.*.unit_price_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'lines.*.discount_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000000000'],
            'lines.*.tax_bps' => ['nullable', 'integer', 'between:0,10000'],
        ]);

        if (array_key_exists('deal_id', $input)) {
            abort_unless($actor->can(DealController::VIEW), 403);

            if ($input['deal_id'] !== null) {
                $input['deal_id'] = $this->records->constrain(Deal::query(), $actor, DealController::VIEW)
                    ->findOrFail($input['deal_id'])->id;
            }
        }

        if (($input['contact_id'] ?? null) !== null) {
            $input['contact_id'] = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')
                ->findOrFail($input['contact_id'])->id;
        }

        if (($input['company_id'] ?? null) !== null) {
            $input['company_id'] = $this->records->constrain(Company::query(), $actor, 'core.contacts.view')
                ->findOrFail($input['company_id'])->id;
        }

        $input['currency'] = strtoupper((string) $input['currency']);
        /** @var list<array<string, mixed>> $lines */
        $lines = $input['lines'];
        unset($input['lines']);

        $usesCatalog = ($input['price_list_id'] ?? null) !== null
            || collect($lines)->contains(fn (array $line): bool => ($line['product_id'] ?? null) !== null);
        abort_if($usesCatalog && ! $actor->can('crm.products.view'), 403);

        return [$input, $lines];
    }

    /** @return array<string, mixed> */
    private function summary(Quote $quote, User $actor): array
    {
        $deal = $quote->deal !== null
            && $actor->can(DealController::VIEW)
            && $this->records->allows($actor, DealController::VIEW, $quote->deal)
                ? ['id' => $quote->deal->id, 'name' => $quote->deal->title]
                : null;

        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'kind' => $quote->kind->value,
            'title' => $quote->title,
            'status' => $quote->status->value,
            'currency' => $quote->currency,
            'subtotalAmount' => $quote->subtotal_amount,
            'discountAmount' => $quote->discount_amount,
            'taxAmount' => $quote->tax_amount,
            'totalAmount' => $quote->total_amount,
            'validUntil' => $quote->valid_until?->toDateString(),
            'deal' => $deal,
            'contact' => $quote->contact === null ? null : ['id' => $quote->contact->id, 'name' => $quote->contact->fullName()],
            'company' => $quote->company?->only(['id', 'name']),
            'owner' => $quote->owner === null ? null : ['id' => $quote->owner->id, 'name' => $quote->owner->user->name],
            'editable' => $quote->status === QuoteStatus::Draft && $this->records->allows($actor, self::MANAGE, $quote),
            'acceptable' => $quote->status === QuoteStatus::Sent && $this->records->allows($actor, self::ACCEPT, $quote),
            'rejectable' => $quote->status === QuoteStatus::Sent && $this->records->allows($actor, self::MANAGE, $quote),
            'cancelable' => in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)
                && $this->records->allows($actor, self::MANAGE, $quote),
            'createdAt' => $quote->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(User $actor, ?Quote $quote = null): array
    {
        $canViewCatalog = $actor->can('crm.products.view');
        $canSelectDeals = $actor->can(DealController::VIEW)
            && ($quote?->deal_id === null || ($quote->deal !== null
                && $this->records->allows($actor, DealController::VIEW, $quote->deal)));

        return [
            'canSelectDeals' => $canSelectDeals,
            'deals' => $canSelectDeals
                ? $this->records->constrain(Deal::query(), $actor, DealController::VIEW)
                    ->orderBy('title')->limit(500)->get(['id', 'title', 'contact_id', 'company_id', 'currency'])
                    ->map(fn (Deal $deal): array => [
                        'id' => $deal->id,
                        'name' => $deal->title,
                        'contactId' => $deal->contact_id,
                        'companyId' => $deal->company_id,
                        'currency' => $deal->currency,
                    ])->all()
                : [],
            'products' => $canViewCatalog
                ? Product::query()->where('is_active', true)->orderBy('name')->limit(500)
                    ->get(['id', 'type', 'sku', 'name', 'description', 'unit', 'currency', 'unit_price_amount', 'tax_bps'])
                    ->map(fn (Product $product): array => [
                        'id' => $product->id,
                        'type' => $product->type->value,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'description' => $product->description,
                        'unit' => $product->unit,
                        'currency' => $product->currency,
                        'unitPriceAmount' => $product->unit_price_amount,
                        'taxBps' => $product->tax_bps,
                    ])->all()
                : [],
            'priceLists' => $canViewCatalog
                ? PriceList::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'currency'])
                    ->map(fn (PriceList $list): array => $list->only(['id', 'name', 'currency']))->all()
                : [],
            'contacts' => $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')
                ->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name'])
                ->map(fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->fullName()])->all(),
            'companies' => $this->records->constrain(Company::query(), $actor, 'core.contacts.view')
                ->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn (Company $company): array => $company->only(['id', 'name']))->all(),
        ];
    }

    /** @return array{canSelectDeals: false, deals: array{}, products: array{}, priceLists: array{}, contacts: array{}, companies: array{}} */
    private function emptyFormOptions(): array
    {
        return ['canSelectDeals' => false, 'deals' => [], 'products' => [], 'priceLists' => [], 'contacts' => [], 'companies' => []];
    }
}
