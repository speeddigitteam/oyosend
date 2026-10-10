<?php

declare(strict_types=1);

use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\SubscriptionManager;
use App\Core\Tenancy\TenantContext;
use App\Platform\Enums\PlatformRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/*
 * The invoice pages of the Super Admin panel: every invoice the platform has
 * issued, across workspaces, and the two things an operator does to one -
 * record money that arrived outside a gateway, or withdraw it.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->admin = $this->createPlatformUser();
    $this->support = $this->createPlatformUser(PlatformRole::SupportAdmin);

    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000]);
    $this->business = $this->createPlan(['key' => 'business', 'name' => 'Business', 'monthly_amount' => 450000]);
    $this->global = $this->createPlan(['key' => 'global', 'name' => 'Global', 'currency' => 'USD', 'monthly_amount' => 2900]);

    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->globex = $this->createTenant('globex', ['name' => 'Globex Bangladesh']);
    $this->initech = $this->createTenant('initech', ['name' => 'Initech', 'currency' => 'USD']);

    // One invoice each, a minute apart: paid, unpaid, unpaid in another currency.
    $this->subscribeAndPay($this->acme, $this->starter);
    $this->travel(1)->minutes();
    $this->subscribe($this->globex, $this->business, withTrial: false);
    $this->travel(1)->minutes();
    $this->subscribe($this->initech, $this->global, withTrial: false);

    $this->paid = $this->invoicesOf($this->acme)->sole();
    $this->open = $this->openInvoiceOf($this->globex);
    $this->foreign = $this->openInvoiceOf($this->initech);

    $this->invoiceNow = fn (Invoice $invoice): Invoice => Invoice::withoutTenancy()->findOrFail($invoice->id);

    $this->list = function (string $query = ''): array {
        $rows = [];

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices'.($query === '' ? '' : '?'.$query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$rows): void {
                $rows = $page->toArray()['props']['invoices']['data'];
            });

        return $rows;
    };

    $this->record = fn (Invoice $invoice, array $data = []) => $this
        ->from("http://admin.localhost/billing/invoices/{$invoice->id}")
        ->post("http://admin.localhost/billing/invoices/{$invoice->id}/payments", [
            'method' => 'bank_transfer',
            'paid_at' => '2026-03-10',
            ...$data,
        ]);

    $this->withdraw = fn (Invoice $invoice, array $data = ['reason' => 'Issued twice by mistake.']) => $this
        ->from("http://admin.localhost/billing/invoices/{$invoice->id}")
        ->post("http://admin.localhost/billing/invoices/{$invoice->id}/void", $data);
});

describe('the list of invoices', function () {
    it('lists the invoices of every workspace, newest first, without a tenant context', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Platform/Pages/Billing/Invoices')
                    ->where('app.area', 'platform')
                    ->where('invoices.total', 3)
                    ->where('filters', ['search' => '', 'status' => ''])
                    ->where('offlineMethods', ['bank_transfer', 'cash', 'cheque', 'mobile_banking', 'other'])
                    ->where('canManage', true);

                $rows = collect($page->toArray()['props']['invoices']['data']);

                expect($rows->pluck('number')->all())->toBe([$this->foreign->number, $this->open->number, $this->paid->number])
                    ->and($rows->firstWhere('id', $this->paid->id))->toMatchArray([
                        'kind' => 'subscription',
                        'status' => 'paid',
                        'currency' => 'BDT',
                        'totalAmount' => 150000,
                        'workspace' => ['id' => $this->acme->id, 'name' => 'Acme Trading', 'slug' => 'acme'],
                    ])
                    ->and($rows->firstWhere('id', $this->open->id))->toMatchArray([
                        'status' => 'open',
                        'totalAmount' => 450000,
                        'workspace' => ['id' => $this->globex->id, 'name' => 'Globex Bangladesh', 'slug' => 'globex'],
                    ])
                    ->and($rows->firstWhere('id', $this->foreign->id))->toMatchArray([
                        'currency' => 'USD',
                        'totalAmount' => 2900,
                        'workspace' => ['id' => $this->initech->id, 'name' => 'Initech', 'slug' => 'initech'],
                    ]);
            });

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('still reads across workspaces right after a request that left a tenant active', function () {
        $this->actingAsOwner($this->acme)->get('http://acme.localhost/billing')->assertOk();

        expect(app(TenantContext::class)->id())->toBe($this->acme->id)
            ->and(($this->list)())->toHaveCount(3)
            ->and(app(TenantContext::class)->check())->toBeFalse();
    });

    it('searches by invoice number, workspace name and address', function (Closure $query, array $expected) {
        $slugs = collect(($this->list)($query($this)))->pluck('workspace.slug')->sort()->values()->all();

        expect($slugs)->toBe($expected);
    })->with([
        'a whole number' => [fn ($test) => 'search='.$test->open->number, ['globex']],
        'part of a number' => [fn ($test) => 'search=INV-2026', ['acme', 'globex', 'initech']],
        'a workspace name' => [fn ($test) => 'search=bangla', ['globex']],
        'a workspace address' => [fn ($test) => 'search=init', ['initech']],
        'surrounding spaces' => [fn ($test) => 'search='.urlencode('  Acme  '), ['acme']],
        'no match' => [fn ($test) => 'search=zzz', []],
        // On their own these would match everything if they were passed through as wildcards.
        'a percent sign' => [fn ($test) => 'search='.urlencode('%'), []],
        'an underscore' => [fn ($test) => 'search=_', []],
    ]);

    it('filters by status, alone and together with a search', function (string $query, array $expected) {
        $slugs = collect(($this->list)($query))->pluck('workspace.slug')->sort()->values()->all();

        expect($slugs)->toBe($expected);
    })->with([
        'unpaid' => ['status=open', ['globex', 'initech']],
        'paid' => ['status=paid', ['acme']],
        'withdrawn' => ['status=void', []],
        'unpaid, by name' => ['status=open&search=globex', ['globex']],
        'paid, by a name that is unpaid' => ['status=paid&search=globex', []],
    ]);

    it('echoes the filters it applied', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices?search=+Globex+&status=open')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters', ['search' => 'Globex', 'status' => 'open'])
                ->where('invoices.total', 1));
    });

    it('rejects a filter value it does not know', function (string $query, string $field) {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices?'.$query)
            ->assertSessionHasErrors($field);
    })->with([
        ['status=overdue', 'status'],
        ['status[]=open', 'status'],
        ['search[]=a', 'search'],
        ['search='.str_repeat('a', 101), 'search'],
    ]);

    it('adds up what is owed and what came in this month, per currency', function () {
        $totals = fn (): array => $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices?status=void')
            ->assertOk()
            ->inertiaProps('totals');

        // Whatever the list is filtered to, the totals are the platform's.
        expect($totals())->toBe([
            'open' => [
                ['currency' => 'BDT', 'amount' => 450000, 'count' => 1],
                ['currency' => 'USD', 'amount' => 2900, 'count' => 1],
            ],
            'paidThisMonth' => [
                ['currency' => 'BDT', 'amount' => 150000, 'count' => 1],
            ],
        ]);

        $this->payOpenInvoice($this->globex);
        $this->inTenant($this->initech, function (): void {
            app(InvoiceIssuer::class)->voidOpen();
        });

        // A withdrawn invoice is owed by nobody.
        expect($totals())->toBe([
            'open' => [],
            'paidThisMonth' => [
                ['currency' => 'BDT', 'amount' => 600000, 'count' => 2],
            ],
        ]);

        // A new month starts from nothing.
        $this->travelTo(Carbon::parse('2026-04-01 00:00:01'));

        expect($totals()['paidThisMonth'])->toBe([]);
    });

    it('still lists the invoices of a workspace that was deleted', function () {
        $this->globex->delete();

        $row = collect(($this->list)())->firstWhere('id', $this->open->id);

        expect($row['workspace'])->toBeNull()
            ->and($row['number'])->toBe($this->open->number);
    });

    it('paginates', function () {
        // Withdrawn and issued again, over and over: 26 more invoices.
        foreach (range(1, 26) as $ignored) {
            $this->inTenant($this->globex, function (): void {
                app(InvoiceIssuer::class)->voidOpen();
            });
            $this->runBillingCycle($this->globex);
        }

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices?status=void')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.total', 26)
                ->where('invoices.per_page', 25)
                ->has('invoices.data', 25)
                // The filter travels with the page links.
                ->where('invoices.next_page_url', fn (string $url): bool => str_contains($url, 'status=void') && str_contains($url, 'page=2')));
    });
});

describe('one invoice', function () {
    it('shows the document, the workspace it belongs to and its payments', function () {
        config(['platform.billing.issuer' => ['name' => 'Oyosend Ltd', 'address' => 'Dhaka', 'email' => 'billing@oyosend.test', 'tax_id' => null]]);

        $this->actingAsPlatformUser($this->admin)
            ->get("http://admin.localhost/billing/invoices/{$this->open->id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Platform/Pages/Billing/Invoice')
                    ->where('invoice.id', $this->open->id)
                    ->where('invoice.number', $this->open->number)
                    ->where('invoice.status', 'open')
                    ->where('invoice.kind', 'subscription')
                    ->where('invoice.totalAmount', 450000)
                    ->where('invoice.currency', 'BDT')
                    ->where('invoice.billedTo.name', 'Globex Bangladesh')
                    ->where('invoice.issuer.name', 'Oyosend Ltd')
                    ->where('workspace', ['id' => $this->globex->id, 'name' => 'Globex Bangladesh', 'slug' => 'globex'])
                    ->where('payments', [])
                    ->where('offlineMethods', ['bank_transfer', 'cash', 'cheque', 'mobile_banking', 'other'])
                    ->where('canManage', true);

                expect($page->toArray()['props']['invoice']['lines'])
                    ->toEqual([['type' => 'plan', 'plan' => 'Business', 'interval' => 'monthly', 'amount' => 450000]]);
            });

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('shows only the payments of that invoice', function () {
        $payments = $this->actingAsPlatformUser($this->admin)
            ->get("http://admin.localhost/billing/invoices/{$this->paid->id}")
            ->assertOk()
            ->inertiaProps('payments');

        expect($payments)->toHaveCount(1)
            ->and($payments[0])->toMatchArray([
                'id' => $this->paymentsOf($this->acme)->sole()->id,
                'gateway' => 'offline',
                'method' => 'bank_transfer',
                'status' => 'succeeded',
                'amount' => 150000,
                'note' => null,
                'bankReference' => null,
            ]);
    });

    it('answers 404 for an invoice that does not exist', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices/'.str_repeat('0', 26))
            ->assertNotFound();
    });

    it('is not served on a workspace\'s own address', function () {
        // There the same path is the workspace's invoice page, which only knows its own invoices.
        $this->actingAsOwner($this->acme)
            ->get("http://acme.localhost/billing/invoices/{$this->open->id}")
            ->assertNotFound();
    });
});

describe('recording a payment that arrived outside a gateway', function () {
    it('settles the invoice, starts the plan and keeps what the operator noted', function () {
        $this->actingAsPlatformUser($this->admin);

        ($this->record)($this->open, ['method' => 'cheque', 'reference' => 'CHQ-7781', 'note' => 'Handed in at the Dhaka office.', 'paid_at' => '2026-03-09'])
            ->assertRedirect("http://admin.localhost/billing/invoices/{$this->open->id}")
            ->assertSessionHasNoErrors();

        $invoice = ($this->invoiceNow)($this->open);
        $payment = $this->paymentsOf($this->globex)->sole();
        $subscription = $this->subscriptionOf($this->globex);

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            // The day it was received, on the workspace's clock (Asia/Dhaka).
            ->and($invoice->paid_at->toDateTimeString())->toBe('2026-03-08 18:00:00')
            ->and($payment->gateway)->toBe('offline')
            ->and($payment->method)->toBe('cheque')
            ->and($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->amount)->toBe(450000)
            ->and($payment->currency)->toBe('BDT')
            ->and($payment->invoice_id)->toBe($this->open->id)
            ->and($payment->recorded_by)->toBe($this->admin->id)
            ->and($payment->paid_at->toDateTimeString())->toBe('2026-03-08 18:00:00')
            ->and($payment->details)->toEqual(['bank_reference' => 'CHQ-7781', 'note' => 'Handed in at the Dhaka office.'])
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->current_period_end)->not->toBeNull()
            ->and($subscription->grace_ends_at)->toBeNull();

        // In the workspace's own trail, under the operator who vouched for it.
        $entry = $this->auditTrail($this->globex, 'billing.payment_recorded')->sole();

        expect($entry->tenant_id)->toBe($this->globex->id)
            ->and($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->actor_label)->toBe($this->admin->email)
            ->and($entry->subject_type)->toBe('payment')
            ->and($entry->subject_id)->toBe($payment->id)
            ->and($entry->properties)->toEqual([
                'method' => 'cheque',
                'invoice' => $this->open->number,
                'amount' => 450000,
                'currency' => 'BDT',
            ])
            ->and($this->auditTrail($this->globex, 'billing.invoice_paid'))->toHaveCount(1)
            ->and($this->auditTrail($this->globex, 'billing.subscription_activated'))->toHaveCount(1)
            // Nothing of it in the platform's own trail or another workspace's.
            ->and($this->auditTrail(null, 'billing.payment_recorded'))->toBeEmpty()
            ->and($this->auditTrail($this->initech, 'billing.payment_recorded'))->toBeEmpty()
            ->and(app(TenantContext::class)->check())->toBeFalse();

        // The panel shows the note and the reference with the payment.
        $shown = $this->get("http://admin.localhost/billing/invoices/{$this->open->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('invoice.status', 'paid'))
            ->inertiaProps('payments');

        expect($shown)->toHaveCount(1)
            ->and($shown[0])->toMatchArray([
                'gateway' => 'offline',
                'method' => 'cheque',
                'status' => 'succeeded',
                'note' => 'Handed in at the Dhaka office.',
                'bankReference' => 'CHQ-7781',
            ]);
    });

    it('needs no reference or note', function () {
        $this->actingAsPlatformUser($this->admin);

        ($this->record)($this->open)->assertSessionHasNoErrors();

        expect($this->paymentsOf($this->globex)->sole()->details)->toBeNull()
            ->and(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Paid);
    });

    it('never dates a payment later than now', function () {
        $this->actingAsPlatformUser($this->admin);

        // "Tomorrow" is allowed, because it can already be tomorrow on the workspace's clock.
        ($this->record)($this->open, ['paid_at' => '2026-03-11'])->assertSessionHasNoErrors();

        // Two minutes after the test's 09:00: the invoices were issued a minute apart.
        expect($this->paymentsOf($this->globex)->sole()->paid_at->toDateTimeString())->toBe('2026-03-10 09:02:00');
    });

    it('settles an invoice in the currency it was issued in', function () {
        $this->actingAsPlatformUser($this->admin);

        ($this->record)($this->foreign, ['method' => 'other'])->assertSessionHasNoErrors();

        $payment = $this->paymentsOf($this->initech)->sole();

        expect($payment->currency)->toBe('USD')
            ->and($payment->amount)->toBe(2900)
            ->and($this->subscriptionOf($this->initech)->status)->toBe(SubscriptionStatus::Active)
            // The other workspace's invoice is untouched.
            ->and(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Open);
    });

    it('validates what the operator enters', function (array $data, string $field) {
        $this->actingAsPlatformUser($this->admin);

        ($this->record)($this->open, $data)->assertSessionHasErrors($field);

        expect(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Open)
            ->and($this->paymentsOf($this->globex))->toBeEmpty();
    })->with([
        'no method' => [['method' => ''], 'method'],
        'a method that is not offered' => [['method' => 'crypto'], 'method'],
        'a gateway instead of a method' => [['method' => 'sslcommerz'], 'method'],
        'no date' => [['paid_at' => ''], 'paid_at'],
        'something that is not a date' => [['paid_at' => 'yesterday-ish'], 'paid_at'],
        'a date after tomorrow' => [['paid_at' => '2026-03-12'], 'paid_at'],
        'a reference that is too long' => [['reference' => str_repeat('a', 121)], 'reference'],
        'a note that is too long' => [['note' => str_repeat('a', 501)], 'note'],
    ]);

    it('refuses an invoice that is not waiting for payment', function (string $which) {
        $this->actingAsPlatformUser($this->admin);

        if ($which === 'void') {
            $this->inTenant($this->globex, function (): void {
                app(InvoiceIssuer::class)->voidOpen();
            });
        }

        $invoice = $which === 'paid' ? $this->paid : $this->open;
        $tenant = $which === 'paid' ? $this->acme : $this->globex;
        $before = $this->paymentsOf($tenant)->count();

        ($this->record)($invoice)->assertSessionHasErrors(['invoice' => 'This invoice is not waiting for payment.']);

        expect($this->paymentsOf($tenant))->toHaveCount($before)
            ->and($this->auditTrail($tenant, 'billing.payment_recorded'))->toHaveCount($before);
    })->with(['paid', 'void']);

    it('refuses an offer to change plan that has run out', function () {
        $this->travelTo(Carbon::parse('2026-03-20 09:00:00'));
        $offer = $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->changePlan($this->business, $this->paid->interval))->invoice;

        $this->travel(4)->days();

        $this->actingAsPlatformUser($this->admin);

        ($this->record)($offer, ['paid_at' => '2026-03-24'])->assertSessionHasErrors('invoice');

        expect(($this->invoiceNow)($offer)->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter');
    });

    it('answers 404 for an invoice that does not exist', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/billing/invoices/'.str_repeat('0', 26).'/payments', ['method' => 'cash', 'paid_at' => '2026-03-10'])
            ->assertNotFound();
    });
});

describe('withdrawing an invoice', function () {
    it('needs a reason', function (array $data) {
        $this->actingAsPlatformUser($this->admin);

        ($this->withdraw)($this->open, $data)->assertSessionHasErrors('reason');

        expect(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Open)
            ->and($this->auditTrail($this->globex, 'billing.invoice_withdrawn'))->toBeEmpty();
    })->with([
        'none' => [[]],
        'an empty one' => [['reason' => '']],
        'one too short to mean anything' => [['reason' => 'dup']],
        'one that is too long' => [['reason' => str_repeat('a', 501)]],
        'a list' => [['reason' => ['a', 'b']]],
    ]);

    it('voids the invoice and records who withdrew it and why', function () {
        $this->actingAsPlatformUser($this->admin);

        ($this->withdraw)($this->open)
            ->assertRedirect("http://admin.localhost/billing/invoices/{$this->open->id}")
            ->assertSessionHasNoErrors();

        $invoice = ($this->invoiceNow)($this->open);

        expect($invoice->status)->toBe(InvoiceStatus::Void)
            ->and($invoice->voided_at->toDateTimeString())->toBe('2026-03-10 09:02:00')
            ->and($invoice->paid_at)->toBeNull()
            ->and($this->paymentsOf($this->globex))->toBeEmpty()
            // Withdrawing an invoice does not end or change the subscription.
            ->and($this->subscriptionOf($this->globex)->status)->toBe(SubscriptionStatus::PastDue);

        $entry = $this->auditTrail($this->globex, 'billing.invoice_withdrawn')->sole();

        expect($entry->tenant_id)->toBe($this->globex->id)
            ->and($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('invoice')
            ->and($entry->subject_id)->toBe($this->open->id)
            ->and($entry->properties)->toEqual(['reason' => 'Issued twice by mistake.'])
            ->and($this->auditTrail($this->globex, 'billing.invoice_voided'))->toHaveCount(1)
            ->and($this->auditTrail(null, 'billing.invoice_withdrawn'))->toBeEmpty()
            ->and(app(TenantContext::class)->check())->toBeFalse();

        // The page now shows it as withdrawn, and the totals no longer count it.
        $this->get("http://admin.localhost/billing/invoices/{$this->open->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoice.status', 'void')
                ->where('invoice.voidedAt', '2026-03-10T09:02:00+00:00'));

        expect($this->get('http://admin.localhost/billing/invoices')->inertiaProps('totals.open'))
            ->toBe([['currency' => 'USD', 'amount' => 2900, 'count' => 1]]);
    });

    it('leaves it to the billing run to ask again for what is still owed', function () {
        $this->actingAsPlatformUser($this->admin);
        ($this->withdraw)($this->open);

        $this->runBillingCycle($this->globex);

        $again = $this->openInvoiceOf($this->globex);

        expect($again)->not->toBeNull()
            ->and($again->id)->not->toBe($this->open->id)
            ->and($again->total_amount)->toBe(450000);
    });

    it('does not withdraw an invoice that is already paid or withdrawn', function (string $which) {
        $this->actingAsPlatformUser($this->admin);

        if ($which === 'void') {
            ($this->withdraw)($this->open)->assertSessionHasNoErrors();
        }

        $invoice = $which === 'paid' ? $this->paid : $this->open;
        $tenant = $which === 'paid' ? $this->acme : $this->globex;
        $before = $this->auditTrail($tenant, 'billing.invoice_withdrawn')->count();
        $status = ($this->invoiceNow)($invoice)->status;

        ($this->withdraw)($invoice, ['reason' => 'Trying to withdraw it once more.'])->assertSessionHasErrors('invoice');

        // Nothing happened, so nothing is recorded as having happened.
        expect(($this->invoiceNow)($invoice)->status)->toBe($status)
            ->and($this->auditTrail($tenant, 'billing.invoice_withdrawn'))->toHaveCount($before)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
    })->with(['paid', 'void']);

    it('answers 404 for an invoice that does not exist', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/billing/invoices/'.str_repeat('0', 26).'/void', ['reason' => 'No such invoice.'])
            ->assertNotFound();
    });
});

describe('who may use the invoice pages', function () {
    it('lets a support admin look, without the controls', function () {
        $this->actingAsPlatformUser($this->support);

        $this->get('http://admin.localhost/billing/invoices')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.total', 3)
                ->where('canManage', false)
                ->where('auth.abilities', fn ($abilities): bool => collect($abilities)->contains('platform.billing.view')
                    && ! collect($abilities)->contains('platform.billing.manage')));

        $this->get("http://admin.localhost/billing/invoices/{$this->open->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoice.id', $this->open->id)
                ->where('canManage', false));
    });

    it('does not let a support admin record a payment or withdraw an invoice', function () {
        $this->actingAsPlatformUser($this->support);

        ($this->record)($this->open)->assertForbidden();
        ($this->withdraw)($this->open)->assertForbidden();

        expect(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Open)
            ->and($this->paymentsOf($this->globex))->toBeEmpty()
            ->and($this->subscriptionOf($this->globex)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->auditTrail($this->globex, 'billing.payment_recorded'))->toBeEmpty()
            ->and($this->auditTrail($this->globex, 'billing.invoice_withdrawn'))->toBeEmpty();
    });

    it('tells a super admin the abilities the pages hide controls by', function () {
        $abilities = $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/billing/invoices')
            ->inertiaProps('auth.abilities');

        expect($abilities)->toContain('platform.billing.view')
            ->toContain('platform.billing.manage')
            ->toContain('platform.tenants.view');
    });

    it('sends guests to the panel\'s sign-in page', function () {
        $this->get('http://admin.localhost/billing/invoices')->assertRedirect('http://admin.localhost/login');
        $this->get("http://admin.localhost/billing/invoices/{$this->open->id}")->assertRedirect('http://admin.localhost/login');

        ($this->record)($this->open)->assertRedirect('http://admin.localhost/login');
        ($this->withdraw)($this->open)->assertRedirect('http://admin.localhost/login');

        expect(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Open);
    });

    it('does not accept a workspace sign-in', function () {
        $this->actingAsOwner($this->globex);

        $this->get('http://admin.localhost/billing/invoices')->assertRedirect('http://admin.localhost/login');
        ($this->record)($this->open)->assertRedirect('http://admin.localhost/login');

        expect(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Open);
    });

    it('asks an operator without two-step verification to set it up first', function () {
        $this->actingAsPlatformUser($this->createPlatformUser(twoFactor: false));

        $this->get('http://admin.localhost/billing/invoices')->assertRedirect('http://admin.localhost/two-factor/setup');
        ($this->record)($this->open)->assertRedirect('http://admin.localhost/two-factor/setup');

        expect(($this->invoiceNow)($this->open)->status)->toBe(InvoiceStatus::Open);
    });
});
