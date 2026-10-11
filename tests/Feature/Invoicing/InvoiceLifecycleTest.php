<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Core\Contacts\Models\Company;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\InvoiceMath;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Events\InvoiceIssued;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = $this->createTenant('inv-life', ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    $this->entitle($this->tenant, 'invoicing');
    $this->membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->company = $this->inTenant($this->tenant, fn () => Company::factory()->create([
        'name' => 'Acme Traders Ltd',
        'tax_id' => 'BIN-123',
        'address' => 'House 4, Road 7',
        'city' => 'Dhaka',
        'country' => 'BD',
        'email' => 'accounts@acme.test',
    ]));

    $this->draft = function (array $overrides = [], ?array $lines = null): Invoice {
        return $this->inTenant($this->tenant, fn (): Invoice => app(InvoiceManager::class)->create([
            'company_id' => $this->company->id,
            'currency' => 'BDT',
            'title' => 'Consulting',
            ...$overrides,
        ], $lines ?? [[
            'name' => 'Implementation',
            'quantity_milli' => 1500,
            'unit_price_amount' => 25_000,
            'discount_amount' => 2_500,
            'tax_bps' => 1500,
        ]], $this->membership));
    };
});

it('calculates exact integer totals on the server', function () {
    $invoice = ($this->draft)();

    // gross 1.5 x 250.00 = 375.00; minus 25.00 = 350.00; tax 15% = 52.50; total 402.50
    expect($invoice->subtotal_amount)->toBe(37_500)
        ->and($invoice->discount_amount)->toBe(2_500)
        ->and($invoice->tax_amount)->toBe(5_250)
        ->and($invoice->total_amount)->toBe(40_250)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->lines)->toHaveCount(1);
});

it('rounds half up per line and sums the rounded lines', function () {
    // 3 x 0.333 -> qty 3000, price 333 => 999; tax 7.5% of 999 = 74.925 -> 75
    expect(InvoiceMath::line(333, 3000, null, null, 750))->toMatchArray([
        'gross_amount' => 999, 'net_amount' => 999, 'tax_amount' => 75, 'line_total_amount' => 1074,
    ]);
    // 0.5 unit x 101 = 50.5 -> 51 (half up)
    expect(InvoiceMath::line(101, 500, null, null, 0)['gross_amount'])->toBe(51);
    // a percentage discount is rounded half up too: 10.05% of 1000
    expect(InvoiceMath::line(1000, 1000, null, 1005, 0)['discount_amount'])->toBe(101)
        ->and(InvoiceMath::line(5, 1000, null, 1000, 0)['discount_amount'])->toBe(1);

    $invoice = ($this->draft)([], [
        ['name' => 'A', 'quantity_milli' => 3000, 'unit_price_amount' => 333, 'tax_bps' => 750],
        ['name' => 'B', 'quantity_milli' => 3000, 'unit_price_amount' => 333, 'tax_bps' => 750],
    ]);

    expect($invoice->tax_amount)->toBe(150)
        ->and($invoice->total_amount)->toBe(2148);
});

it('handles very large amounts without overflow or float drift', function () {
    $line = InvoiceMath::line(InvoiceMath::MAX_UNIT_PRICE, InvoiceMath::MAX_QUANTITY_MILLI, null, null, 10_000);

    expect($line['gross_amount'])->toBe(10_000_000_000_000_000)
        ->and($line['tax_amount'])->toBe(10_000_000_000_000_000)
        ->and($line['line_total_amount'])->toBe(20_000_000_000_000_000);
    expect(fn () => InvoiceMath::line(InvoiceMath::MAX_UNIT_PRICE + 1, 1000, null, null, 0))
        ->toThrow(ValidationException::class);
    expect(fn () => InvoiceMath::line(100, 1000, 101, null, 0))->toThrow(ValidationException::class);
    expect(fn () => InvoiceMath::line(100, 1000, 10, 500, 0))->toThrow(ValidationException::class);
    expect(InvoiceMath::quantityLabel(1500))->toBe('1.5')
        ->and(InvoiceMath::quantityLabel(2000))->toBe('2')
        ->and(InvoiceMath::quantityLabel(125))->toBe('0.125');
});

it('resolves a tax rate in basis points and snapshots it on the line', function () {
    $rate = $this->inTenant($this->tenant, fn () => TaxRate::query()->create(['name' => 'VAT 15%', 'rate_bps' => 1500]));
    $invoice = ($this->draft)([], [[
        'name' => 'Widget', 'quantity_milli' => 2000, 'unit_price_amount' => 1000, 'tax_rate_id' => $rate->id,
    ]]);

    $this->inTenant($this->tenant, function () use ($rate, $invoice): void {
        $rate->update(['rate_bps' => 500, 'name' => 'Renamed']);
        $line = $invoice->lines()->sole();

        expect($line->tax_bps)->toBe(1500)
            ->and($line->tax_name)->toBe('VAT 15%')
            ->and($line->tax_amount)->toBe(300);
    });

    expect(fn () => ($this->draft)([], [[
        'name' => 'Widget', 'quantity_milli' => 1000, 'unit_price_amount' => 1000, 'tax_rate_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]]))->toThrow(ValidationException::class);
});

it('issues a draft once: number, immutable customer snapshot, frozen lines and an event', function () {
    Event::fake([InvoiceIssued::class]);
    $invoice = ($this->draft)();

    $issued = $this->inTenant($this->tenant, fn () => app(InvoiceManager::class)->issue($invoice));

    expect($issued->number)->toBe('INV-0001')
        ->and($issued->status)->toBe(InvoiceStatus::Issued)
        ->and($issued->balance_amount)->toBe(40_250)
        ->and($issued->issued_on->toDateString())->toBe(now('Asia/Dhaka')->toDateString())
        ->and($issued->due_on->toDateString())->toBe(now('Asia/Dhaka')->addDays(14)->toDateString())
        ->and($issued->customer_name)->toBe('Acme Traders Ltd')
        ->and($issued->customer_tax_id)->toBe('BIN-123')
        ->and($issued->customer_address)->toBe("House 4, Road 7\nDhaka\nBD")
        ->and($issued->seller_snapshot['trade_name'])->toBe($this->tenant->name);

    Event::assertDispatched(InvoiceIssued::class, fn (InvoiceIssued $event): bool => $event->payload()['invoice_id'] === $issued->id
        && $event->payload()['total_amount'] === 40_250
        && $event->tenantId() === $this->tenant->id);

    $this->inTenant($this->tenant, function () use ($issued): void {
        // A later edit of the shared company never rewrites the issued document.
        Company::query()->findOrFail($this->company->id)->update(['name' => 'Renamed Co', 'tax_id' => 'NEW']);
        $fresh = Invoice::query()->findOrFail($issued->id);

        expect($fresh->customer_name)->toBe('Acme Traders Ltd')
            ->and($fresh->customer_tax_id)->toBe('BIN-123');

        expect(fn () => $fresh->update(['customer_name' => 'Tampered']))->toThrow(LogicException::class)
            ->and(fn () => $fresh->update(['total_amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => InvoiceLine::query()->where('invoice_id', $fresh->id)->firstOrFail()->update(['name' => 'Tampered']))
            ->toThrow(LogicException::class)
            ->and(fn () => app(InvoiceManager::class)->update($fresh, [
                'company_id' => $this->company->id, 'currency' => 'BDT',
            ], [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 1]]))
            ->toThrow(ValidationException::class)
            ->and(fn () => app(InvoiceManager::class)->issue($fresh))->toThrow(ValidationException::class)
            ->and(fn () => app(InvoiceManager::class)->delete($fresh))->toThrow(ValidationException::class);
    });
});

it('refuses to issue an invoice with no positive total or with a vanished customer', function () {
    $free = ($this->draft)([], [['name' => 'Free', 'quantity_milli' => 1000, 'unit_price_amount' => 0]]);

    expect(fn () => $this->inTenant($this->tenant, fn () => app(InvoiceManager::class)->issue($free)))
        ->toThrow(ValidationException::class);

    $invoice = ($this->draft)();
    $this->inTenant($this->tenant, fn () => Company::query()->findOrFail($this->company->id)->delete());

    expect(fn () => $this->inTenant($this->tenant, fn () => app(InvoiceManager::class)->issue($invoice)))
        ->toThrow(ValidationException::class);
});

it('updates and deletes drafts only', function () {
    $invoice = ($this->draft)();

    $this->inTenant($this->tenant, function () use ($invoice): void {
        $updated = app(InvoiceManager::class)->update($invoice, [
            'company_id' => $this->company->id, 'currency' => 'USD', 'title' => 'Revised',
        ], [['name' => 'Hours', 'quantity_milli' => 2000, 'unit_price_amount' => 10_000]]);

        expect($updated->total_amount)->toBe(20_000)
            ->and($updated->currency)->toBe('USD')
            ->and($updated->lines)->toHaveCount(1);

        app(InvoiceManager::class)->delete($invoice);
        expect(Invoice::query()->count())->toBe(0)
            ->and(InvoiceLine::query()->count())->toBe(0);
    });
});

it('voids an unpaid invoice, keeps its number and refuses to void a paid or credited one', function () {
    $invoice = ($this->draft)();
    $manager = fn () => app(InvoiceManager::class);

    $this->inTenant($this->tenant, function () use ($invoice, $manager): void {
        $issued = $manager()->issue($invoice);
        $voided = $manager()->void($issued, 'Entered twice');

        expect($voided->status)->toBe(InvoiceStatus::Void)
            ->and($voided->number)->toBe('INV-0001')
            ->and($voided->balance_amount)->toBe(0)
            ->and($voided->void_reason)->toBe('Entered twice')
            ->and($manager()->void($voided, 'again')->status)->toBe(InvoiceStatus::Void);

        $second = $manager()->issue(($this->draft)());
        expect($second->number)->toBe('INV-0002');
    });
});

it('enforces the invoices per month plan limit when issuing', function () {
    $plan = $this->createFreePlan(['key' => 'two-invoices', 'modules' => ['invoicing'], 'limits' => ['invoicing.invoices_per_month' => 2]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $this->inTenant($this->tenant, function (): void {
        $manager = app(InvoiceManager::class);
        $manager->issue(($this->draft)());
        $manager->issue(($this->draft)());

        expect(app(UsageLimits::class)->used('invoicing.invoices_per_month'))->toBe(2)
            ->and(app(UsageLimits::class)->remaining('invoicing.invoices_per_month'))->toBe(0);

        $third = ($this->draft)();
        expect(fn () => $manager->issue($third))->toThrow(ValidationException::class);
        expect($third->refresh()->status)->toBe(InvoiceStatus::Draft)
            ->and($third->number)->toBeNull();
    });
});
