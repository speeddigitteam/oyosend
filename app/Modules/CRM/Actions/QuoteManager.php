<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\QuoteAccepted;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\PriceList;
use App\Modules\CRM\Models\PriceListItem;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QuoteManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CustomerLifecycleManager $customers,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $attributes, array $lines, TenantMembership $owner): Quote
    {
        $currency = strtoupper((string) $attributes['currency']);
        $this->assertCustomer($attributes, $currency);
        $priceList = $this->priceList($attributes['price_list_id'] ?? null, $currency);
        $snapshots = $this->snapshots($lines, $currency, $priceList);
        $totals = $this->totals($snapshots);

        return DB::transaction(function () use ($attributes, $currency, $priceList, $snapshots, $totals, $owner): Quote {
            $kind = QuoteKind::from((string) ($attributes['kind'] ?? QuoteKind::Quotation->value));
            $quote = new Quote([
                ...$attributes,
                'price_list_id' => $priceList?->id,
                'number' => $this->number($kind),
                'kind' => $kind,
                'status' => QuoteStatus::Draft,
                'currency' => $currency,
                ...$totals,
            ]);
            $quote->assignOwner($owner);
            $quote->save();

            $this->saveLines($quote, $snapshots);
            $this->customers->touchProspect($quote->contact_id, $quote->company_id, $quote->owner()->first());

            return $quote->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Quote $quote, array $attributes, array $lines): Quote
    {
        $currency = strtoupper((string) $attributes['currency']);
        $this->assertCustomer([
            ...$attributes,
            'deal_id' => array_key_exists('deal_id', $attributes) ? $attributes['deal_id'] : $quote->deal_id,
        ], $currency);
        $priceList = $this->priceList($attributes['price_list_id'] ?? null, $currency);
        $snapshots = $this->snapshots($lines, $currency, $priceList);
        $totals = $this->totals($snapshots);

        return DB::transaction(function () use ($quote, $attributes, $currency, $priceList, $snapshots, $totals): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, QuoteStatus::Draft, 'Only a draft quotation can be edited.');

            foreach ($locked->lines()->get() as $line) {
                $line->delete();
            }

            $locked->fill([
                ...$attributes,
                'price_list_id' => $priceList?->id,
                'currency' => $currency,
                ...$totals,
            ]);
            $locked->save();
            $this->saveLines($locked, $snapshots);

            return $locked->load('lines');
        });
    }

    public function send(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, QuoteStatus::Draft, 'Only a draft quotation can be sent.');

            if (! $locked->lines()->exists()) {
                throw ValidationException::withMessages(['lines' => __('A quotation needs at least one line.')]);
            }

            if ($locked->valid_until !== null && $locked->valid_until->toDateString() < $this->localToday()) {
                throw ValidationException::withMessages(['valid_until' => __('The quotation has already expired.')]);
            }

            $locked->status = QuoteStatus::Sent;
            $locked->sent_at = now();
            $locked->save();
            $this->audit->record('crm.quote.sent', $locked, ['number' => $locked->number]);

            return $locked;
        });
    }

    public function reject(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, QuoteStatus::Sent, 'Only a sent quotation can be rejected.');
            $locked->status = QuoteStatus::Rejected;
            $locked->rejected_at = now();
            $locked->save();
            $this->audit->record('crm.quote.rejected', $locked, ['number' => $locked->number]);

            return $locked;
        });
    }

    public function cancel(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === QuoteStatus::Canceled) {
                return $locked;
            }

            if (! in_array($locked->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)) {
                throw ValidationException::withMessages(['quote' => __('Only a draft or sent quotation can be canceled.')]);
            }

            $locked->status = QuoteStatus::Canceled;
            $locked->save();
            $this->audit->record('crm.quote.canceled', $locked, ['number' => $locked->number]);

            return $locked;
        });
    }

    public function accept(Quote $quote): SalesOrder
    {
        $order = DB::transaction(function () use ($quote): ?SalesOrder {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === QuoteStatus::Accepted) {
                return SalesOrder::query()->where('quote_id', $locked->id)->firstOrFail();
            }

            $this->assertStatus($locked, QuoteStatus::Sent, 'Only a sent quotation can be accepted.');

            if ($locked->valid_until !== null && $locked->valid_until->toDateString() < $this->localToday()) {
                $locked->status = QuoteStatus::Expired;
                $locked->save();
                $this->audit->record('crm.quote.expired', $locked, ['number' => $locked->number]);

                return null;
            }

            $acceptedAt = now();
            $order = new SalesOrder([
                'quote_id' => $locked->id,
                'contact_id' => $locked->contact_id,
                'company_id' => $locked->company_id,
                'number' => 'SO-'.strtoupper(substr((string) Str::ulid(), -12)),
                'status' => SalesOrderStatus::Confirmed,
                'currency' => $locked->currency,
                'subtotal_amount' => $locked->subtotal_amount,
                'discount_amount' => $locked->discount_amount,
                'tax_amount' => $locked->tax_amount,
                'total_amount' => $locked->total_amount,
                'terms' => $locked->terms,
                'notes' => $locked->notes,
                'ordered_at' => $acceptedAt,
            ]);
            $order->assignOwner($locked->owner()->first());
            $order->save();

            foreach ($locked->lines()->orderBy('position')->get() as $line) {
                SalesOrderLine::query()->create([
                    'sales_order_id' => $order->id,
                    'quote_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'position' => $line->position,
                    'item_type' => $line->item_type,
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'unit' => $line->unit,
                    'description' => $line->description,
                    'quantity_milli' => $line->quantity_milli,
                    'currency' => $line->currency,
                    'unit_price_amount' => $line->unit_price_amount,
                    'discount_amount' => $line->discount_amount,
                    'tax_bps' => $line->tax_bps,
                    'tax_amount' => $line->tax_amount,
                    'line_total_amount' => $line->line_total_amount,
                ]);
            }

            $locked->status = QuoteStatus::Accepted;
            $locked->accepted_at = $acceptedAt;
            $locked->probability_percent = 100;
            $locked->save();

            // A linked deal is the canonical lifetime-value recognition source. Its win records value once;
            // accepting the quote only recognizes value when no opportunity owns the sale.
            if ($locked->deal_id === null) {
                $this->customers->recordWon(
                    $locked->contact_id,
                    $locked->company_id,
                    $order->total_amount,
                    $order->currency,
                    $acceptedAt,
                    $locked->owner()->first(),
                );
            }
            $this->audit->record('crm.quote.accepted', $locked, ['sales_order_id' => $order->id]);
            QuoteAccepted::dispatch($locked, $order);

            return $order->load('lines');
        });

        if ($order === null) {
            throw ValidationException::withMessages(['quote' => __('The quotation has expired.')]);
        }

        return $order;
    }

    /** @param array<string, mixed> $attributes */
    private function assertCustomer(array $attributes, string $currency): void
    {
        $contactId = $attributes['contact_id'] ?? null;
        $companyId = $attributes['company_id'] ?? null;

        if ($contactId === null && $companyId === null) {
            throw ValidationException::withMessages(['customer' => __('Choose a contact or company.')]);
        }

        if ($contactId !== null) {
            Contact::query()->findOrFail($contactId);
        }

        if ($companyId !== null) {
            Company::query()->findOrFail($companyId);
        }

        $dealId = $attributes['deal_id'] ?? null;

        if ($dealId !== null) {
            $deal = Deal::query()->findOrFail($dealId);

            if ($deal->contact_id !== $contactId || $deal->company_id !== $companyId || $deal->currency !== $currency) {
                throw ValidationException::withMessages([
                    'deal_id' => __('The selected deal must have the same customer and currency as the quotation.'),
                ]);
            }
        }
    }

    private function priceList(mixed $id, string $currency): ?PriceList
    {
        if ($id === null || $id === '') {
            return null;
        }

        /** @var PriceList $priceList */
        $priceList = PriceList::query()->findOrFail($id);

        if (! $priceList->is_active || $priceList->currency !== $currency
            || ($priceList->starts_on !== null && $priceList->starts_on->toDateString() > $this->localToday())
            || ($priceList->ends_on !== null && $priceList->ends_on->toDateString() < $this->localToday())) {
            throw ValidationException::withMessages(['price_list_id' => __('The selected price list is not available for this currency and date.')]);
        }

        return $priceList;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function snapshots(array $lines, string $currency, ?PriceList $priceList): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('A quotation needs at least one line.')]);
        }

        $snapshots = [];

        foreach ($lines as $index => $input) {
            $product = isset($input['product_id'])
                ? Product::query()->findOrFail($input['product_id'])
                : null;

            if ($product !== null && ! $product->is_active) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => __('The selected catalog item is inactive.')]);
            }
            $quantity = (int) ($input['quantity_milli'] ?? 0);
            $amount = $this->unitPrice($input, $product, $priceList, $quantity, $currency);
            $discount = (int) ($input['discount_amount'] ?? 0);
            $taxBps = (int) ($input['tax_bps'] ?? ($product === null ? 0 : $product->tax_bps));
            $calculated = SalesMath::line($amount, $quantity, $discount, $taxBps);
            $name = trim((string) ($product === null ? ($input['name'] ?? '') : $product->name));

            if ($name === '') {
                throw ValidationException::withMessages(["lines.{$index}.name" => __('A custom line needs a name.')]);
            }

            $snapshots[] = [
                'product_id' => $product?->id,
                'position' => $index + 1,
                'item_type' => $product === null
                    ? CatalogItemType::from((string) ($input['item_type'] ?? CatalogItemType::Service->value))
                    : $product->type,
                'sku' => $product?->sku,
                'name' => $name,
                'unit' => trim((string) ($product === null ? ($input['unit'] ?? 'unit') : $product->unit)),
                'description' => $input['description'] ?? $product?->description,
                'quantity_milli' => $quantity,
                'currency' => $currency,
                'unit_price_amount' => $amount,
                'discount_amount' => $discount,
                'tax_bps' => $taxBps,
                'tax_amount' => $calculated['tax_amount'],
                'line_total_amount' => $calculated['total_amount'],
                'gross_amount' => $calculated['gross_amount'],
            ];
        }

        return $snapshots;
    }

    /** @param array<string, mixed> $input */
    private function unitPrice(
        array $input,
        ?Product $product,
        ?PriceList $priceList,
        int $quantity,
        string $currency,
    ): int {
        if ($product === null) {
            if (array_key_exists('unit_price_amount', $input) && $input['unit_price_amount'] !== null) {
                return (int) $input['unit_price_amount'];
            }

            throw ValidationException::withMessages(['lines' => __('A custom line needs a unit price.')]);
        }

        if ($priceList !== null) {
            $price = PriceListItem::query()
                ->where('price_list_id', $priceList->id)
                ->where('product_id', $product->id)
                ->where('minimum_quantity_milli', '<=', $quantity)
                ->orderByDesc('minimum_quantity_milli')
                ->first();

            if ($price === null) {
                throw ValidationException::withMessages(['lines' => __('The price list has no matching price for a selected item.')]);
            }

            return $price->unit_price_amount;
        }

        if (array_key_exists('unit_price_amount', $input) && $input['unit_price_amount'] !== null) {
            return (int) $input['unit_price_amount'];
        }

        if ($product->currency !== $currency) {
            throw ValidationException::withMessages(['lines' => __('Enter an explicit unit price when the product currency differs from the quotation.')]);
        }

        return $product->unit_price_amount;
    }

    private function localToday(): string
    {
        return CarbonImmutable::now($this->context->require()->timezone)->toDateString();
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return array{subtotal_amount: int, discount_amount: int, tax_amount: int, total_amount: int}
     */
    private function totals(array $snapshots): array
    {
        return [
            'subtotal_amount' => SalesMath::sum(array_column($snapshots, 'gross_amount')),
            'discount_amount' => SalesMath::sum(array_column($snapshots, 'discount_amount')),
            'tax_amount' => SalesMath::sum(array_column($snapshots, 'tax_amount')),
            'total_amount' => SalesMath::sum(array_column($snapshots, 'line_total_amount')),
        ];
    }

    /** @param list<array<string, mixed>> $snapshots */
    private function saveLines(Quote $quote, array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            unset($snapshot['gross_amount']);
            $quote->lines()->create($snapshot);
        }
    }

    private function assertStatus(Quote $quote, QuoteStatus $expected, string $message): void
    {
        if ($quote->status !== $expected) {
            throw ValidationException::withMessages(['quote' => __($message)]);
        }
    }

    private function number(QuoteKind $kind): string
    {
        $prefix = $kind === QuoteKind::Proposal ? 'PR' : 'QT';

        return $prefix.'-'.strtoupper(substr((string) Str::ulid(), -12));
    }
}
