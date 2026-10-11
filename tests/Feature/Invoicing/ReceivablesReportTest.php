<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Modules\Invoicing\Actions\CreditNoteManager;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\PaymentManager;
use App\Modules\Invoicing\Actions\Receivables;
use App\Modules\Invoicing\Models\Invoice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->tenant = $this->createTenant('inv-report', ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    $this->entitle($this->tenant, 'invoicing');
    $this->membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00', 'Asia/Dhaka'));

    [$this->acme, $this->rafi] = $this->inTenant($this->tenant, fn (): array => [
        Company::factory()->create(['name' => 'Acme Ltd']),
        Contact::factory()->create(['first_name' => 'Rafi', 'last_name' => 'Khan']),
    ]);

    $this->invoice = function (array $customer, string $currency, int $amount, string $dueOn, string $issuedOn = '2026-01-10'): Invoice {
        return $this->inTenant($this->tenant, function () use ($customer, $currency, $amount, $dueOn, $issuedOn): Invoice {
            $manager = app(InvoiceManager::class);

            return $manager->issue($manager->create([
                ...$customer, 'currency' => $currency, 'issued_on' => $issuedOn, 'due_on' => $dueOn,
            ], [['name' => 'Work', 'quantity_milli' => 1000, 'unit_price_amount' => $amount]], $this->membership));
        });
    };
});

it('ages open balances into buckets per currency and never mixes currencies', function () {
    $acme = ['company_id' => $this->acme->id];
    $rafi = ['contact_id' => $this->rafi->id];

    ($this->invoice)($acme, 'BDT', 100_000, '2026-06-20');                    // not yet due
    $partlyPaid = ($this->invoice)($acme, 'BDT', 50_000, '2026-06-05');      // 10 days late
    ($this->invoice)($rafi, 'BDT', 40_000, '2026-04-20');                     // 56 days late
    ($this->invoice)($rafi, 'BDT', 10_000, '2026-02-01');                     // over 90 days
    ($this->invoice)($acme, 'USD', 7_000, '2026-06-01');                      // 14 days late, other currency
    $settled = ($this->invoice)($acme, 'BDT', 9_999, '2026-06-01');          // paid: not receivable

    $this->inTenant($this->tenant, function () use ($partlyPaid, $settled): void {
        $payments = app(PaymentManager::class);
        $payments->record($partlyPaid, ['amount' => 20_000, 'method' => 'bank_transfer', 'paid_on' => '2026-06-10']);
        $payments->record($settled, ['amount' => 9_999, 'method' => 'cash', 'paid_on' => '2026-06-12']);
        $reversed = $payments->record($partlyPaid, ['amount' => 5_000, 'method' => 'cash', 'paid_on' => '2026-06-11']);
        $payments->reverse($reversed, 'Entered on the wrong invoice');

        $report = app(Receivables::class)->report('2026-06-01', '2026-06-30');

        expect($report['asOf'])->toBe('2026-06-15')
            ->and($report['ageing'])->toBe([
                [
                    'currency' => 'BDT', 'invoices' => 4, 'outstanding' => 180_000,
                    'buckets' => ['current' => 100_000, 'days_1_30' => 30_000, 'days_31_60' => 40_000, 'days_61_90' => 0, 'days_over_90' => 10_000],
                ],
                [
                    'currency' => 'USD', 'invoices' => 1, 'outstanding' => 7_000,
                    'buckets' => ['current' => 0, 'days_1_30' => 7_000, 'days_31_60' => 0, 'days_61_90' => 0, 'days_over_90' => 0],
                ],
            ])
            ->and($report['collected'])->toBe([['currency' => 'BDT', 'payments' => 2, 'amount' => 29_999]])
            ->and($report['collectedByMethod'])->toBe([
                ['currency' => 'BDT', 'method' => 'bank_transfer', 'payments' => 1, 'amount' => 20_000],
                ['currency' => 'BDT', 'method' => 'cash', 'payments' => 1, 'amount' => 9_999],
            ])
            ->and($report['byCustomer'][0])->toMatchArray([
                'companyId' => $this->acme->id, 'currency' => 'BDT', 'invoices' => 2, 'outstanding' => 130_000,
                'oldestDueOn' => '2026-06-05', 'name' => 'Acme Ltd',
            ])
            ->and($report['byCustomer'])->toHaveCount(3);
    });
});

it('reports what was invoiced and credited in a range and filters by currency', function () {
    $acme = ['company_id' => $this->acme->id];
    $first = ($this->invoice)($acme, 'BDT', 100_000, '2026-02-01', '2026-01-10');
    ($this->invoice)($acme, 'USD', 5_000, '2026-02-01', '2026-01-20');
    ($this->invoice)($acme, 'BDT', 1_000, '2026-07-01', '2026-06-10');

    $this->inTenant($this->tenant, function () use ($first): void {
        // The credit note is dated today (2026-06-15), outside January.
        app(CreditNoteManager::class)->issue($first, 'Discount', [['name' => 'Credit', 'quantity_milli' => 1000, 'unit_price_amount' => 10_000]]);
        $reports = app(Receivables::class);

        expect($reports->report('2026-01-01', '2026-01-31')['invoiced'])->toBe([
            ['currency' => 'BDT', 'invoices' => 1, 'total' => 100_000, 'tax' => 0, 'credited' => 0],
            ['currency' => 'USD', 'invoices' => 1, 'total' => 5_000, 'tax' => 0, 'credited' => 0],
        ])
            ->and($reports->report('2026-06-01', '2026-06-30', 'bdt')['invoiced'])->toBe([
                ['currency' => 'BDT', 'invoices' => 1, 'total' => 1_000, 'tax' => 0, 'credited' => 10_000],
            ])
            ->and(array_column($reports->report(null, null, 'USD')['ageing'], 'currency'))->toBe(['USD']);
    });
});

it('serves the report only to people who may view reports', function () {
    $member = $this->addMember($this->tenant, $this->createRole($this->tenant, 'Biller', ['invoicing.invoices.view']));
    $analyst = $this->addMember($this->tenant, $this->createRole($this->tenant, 'Analyst', ['invoicing.reports.view']));
    $url = $this->tenantUrl($this->tenant, '/invoicing/reports');

    $this->actingAsMember($this->tenant, $member)->get($url)->assertForbidden();
    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $analyst)->get($url.'?from=2026-06-01&to=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.asOf', '2026-06-15')
            ->where('filters.from', '2026-06-01')
            ->has('report.ageing')
            ->has('buckets', 5));
});
