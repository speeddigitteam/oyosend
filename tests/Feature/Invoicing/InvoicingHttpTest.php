<?php

declare(strict_types=1);

use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Modules\Invoicing\Actions\CreditNoteManager;
use App\Modules\Invoicing\Actions\InvoiceDocument;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\PaymentManager;
use App\Modules\Invoicing\Actions\RecurringManager;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Mail\InvoiceMail;
use App\Modules\Invoicing\Models\BillableTime;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;
use App\Modules\Invoicing\Models\RecurringTemplate;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->tenant = $this->createTenant('inv-http', ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'name' => 'Seller Workspace']);
    $this->entitle($this->tenant, 'invoicing');
    $this->owner = $this->ownerOf($this->tenant);
    $this->membership = $this->membershipOf($this->tenant, $this->owner);
    $this->company = $this->inTenant($this->tenant, fn () => Company::factory()->create([
        'name' => 'Buyer <b>Co</b>', 'email' => 'buyer@example.test', 'tax_id' => 'TIN-9',
    ]));
    $this->url = fn (string $path = '') => $this->tenantUrl($this->tenant, '/invoicing'.$path);
    $this->payload = fn (array $overrides = []) => [
        'company_id' => $this->company->id,
        'currency' => 'bdt',
        'title' => 'Website project',
        'lines' => [['name' => 'Design', 'quantity_milli' => 2000, 'unit_price_amount' => 50_000, 'tax_bps' => 1000]],
        ...$overrides,
    ];
    $this->makeIssued = fn (): Invoice => $this->inTenant($this->tenant, function (): Invoice {
        $manager = app(InvoiceManager::class);

        return $manager->issue($manager->create(
            ['company_id' => $this->company->id, 'currency' => 'BDT'],
            [['name' => 'Design', 'quantity_milli' => 1000, 'unit_price_amount' => 100_000]],
            $this->membership,
        ));
    });
});

it('walks the whole invoice workflow over HTTP as the workspace owner', function () {
    Mail::fake();
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/invoices'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', true)->has('invoices.data', 0));

    $this->get(($this->url)('/invoices/create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('form.defaults.currency', 'BDT')
            ->has('form.companies', 1));

    $response = $this->post(($this->url)('/invoices'), ($this->payload)());
    $invoice = $this->inTenant($this->tenant, fn () => Invoice::query()->sole());
    $response->assertRedirect();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->currency)->toBe('BDT')
        ->and($invoice->total_amount)->toBe(110_000)
        ->and($invoice->owner_id)->toBe($this->membership->id);

    $this->get(($this->url)("/invoices/{$invoice->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.status', 'draft')
            ->where('invoice.totalAmount', 110_000)
            ->where('invoice.lines.0.taxAmount', 10_000)
            ->where('abilities.edit', true)
            ->where('abilities.issue', true)
            ->where('abilities.recordPayment', false)
            ->has('form.contacts'));

    $this->put(($this->url)("/invoices/{$invoice->id}"), ($this->payload)(['title' => 'Revised']))->assertRedirect();
    $this->post(($this->url)("/invoices/{$invoice->id}/issue"))->assertRedirect();

    $this->post(($this->url)("/invoices/{$invoice->id}/payments"), [
        'amount' => 40_000, 'method' => 'bank_transfer', 'reference' => 'TRX-77', 'idempotency_key' => 'k-1',
    ])->assertRedirect();
    $this->post(($this->url)("/invoices/{$invoice->id}/payments"), [
        'amount' => 40_000, 'method' => 'bank_transfer', 'reference' => 'TRX-77', 'idempotency_key' => 'k-1',
    ])->assertRedirect();
    $this->post(($this->url)("/invoices/{$invoice->id}/payments"), ['amount' => 70_001, 'method' => 'cash'])
        ->assertSessionHasErrors('amount');

    $this->post(($this->url)("/invoices/{$invoice->id}/credit-notes"), [
        'reason' => 'Scope reduced',
        'lines' => [['name' => 'Design', 'quantity_milli' => 100, 'unit_price_amount' => 50_000]],
    ])->assertRedirect();

    $this->post(($this->url)("/invoices/{$invoice->id}/send"), ['message' => 'Thank you'])->assertRedirect();
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->hasTo('buyer@example.test'));

    $this->get(($this->url)("/invoices/{$invoice->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.number', 'INV-0001')
            ->where('invoice.status', 'partially_paid')
            ->where('invoice.paidAmount', 40_000)
            ->where('invoice.creditedAmount', 5_000)
            ->where('invoice.balanceAmount', 65_000)
            ->where('invoice.snapshot.taxId', 'TIN-9')
            ->has('invoice.payments', 1)
            ->has('invoice.creditNotes', 1)
            ->where('abilities.edit', false)
            ->where('abilities.void', false)
            ->where('abilities.recordPayment', true));

    $this->inTenant($this->tenant, function () use ($invoice): void {
        expect(Payment::query()->count())->toBe(1)
            ->and(CreditNote::query()->sole()->number)->toBe('CN-0001');
        $this->assertNotNull(Invoice::query()->findOrFail($invoice->id)->sent_at);
    });
});

it('rejects invalid invoice input with field errors and changes nothing', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/invoices'), ($this->payload)(['lines' => []]))->assertSessionHasErrors('lines');
    $this->post(($this->url)('/invoices'), ($this->payload)(['currency' => 'TAKA']))->assertSessionHasErrors('currency');
    $this->post(($this->url)('/invoices'), ($this->payload)(['company_id' => null]))->assertSessionHasErrors(['contact_id', 'company_id']);
    $this->post(($this->url)('/invoices'), ($this->payload)([
        'lines' => [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 1.5]],
    ]))->assertSessionHasErrors('lines.0.unit_price_amount');
    $this->post(($this->url)('/invoices'), ($this->payload)([
        'lines' => [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 100, 'discount_amount' => 500]],
    ]))->assertSessionHasErrors('lines');
    $this->post(($this->url)('/invoices'), ($this->payload)(['issued_on' => '2026-05-10', 'due_on' => '2026-05-01']))
        ->assertSessionHasErrors('due_on');
    // A client-supplied total or tenant is never read.
    $this->post(($this->url)('/invoices'), ($this->payload)(['total_amount' => 1, 'tenant_id' => 'x', 'status' => 'paid']))->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $invoice = Invoice::query()->sole();
        expect($invoice->total_amount)->toBe(110_000)
            ->and($invoice->status)->toBe(InvoiceStatus::Draft);
    });
});

it('serves a print-optimised, escaped HTML document', function () {
    $invoice = ($this->makeIssued)();

    $this->actingAsOwner($this->tenant)
        ->get(($this->url)("/invoices/{$invoice->id}/document"))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('INV-0001')
        ->assertSee('Buyer &lt;b&gt;Co&lt;/b&gt;', false)
        ->assertDontSee('Buyer <b>Co</b>', false)
        ->assertSee('@media print', false)
        ->assertSee('Print / Save as PDF');

    $this->get(($this->url)("/invoices/{$invoice->id}/document?download=1"))
        ->assertHeader('Content-Disposition', 'attachment; filename="invoice-INV-0001.html"');
});

it('renders the document and mail in the workspace Bengali locale', function () {
    $this->inTenant($this->tenant, fn () => $this->tenant->update(['locale' => 'bn']));
    $invoice = ($this->makeIssued)();
    app()->setLocale('bn');

    $html = $this->inTenant($this->tenant, fn () => app(InvoiceDocument::class)->html($invoice->refresh()));

    expect($html)->toContain('lang="bn"')
        ->and($html)->toContain(__('Bill to'))
        ->and(__('Bill to'))->not->toBe('Bill to');
});

it('gates every route by entitlement, sign-in, permission and the module paywall', function () {
    $invoice = ($this->makeIssued)();
    $biller = $this->addMember($this->tenant, $this->createRole($this->tenant, 'Viewer', ['invoicing.invoices.view']));
    $noModule = $this->createTenant('inv-nomodule');
    $this->inTenant($noModule, fn () => Company::factory()->create());

    // Not entitled: the whole area answers 404, with no permission leak.
    $this->actingAsOwner($noModule)
        ->get($this->tenantUrl($noModule, '/invoicing/invoices'))->assertNotFound();
    $this->startNewBrowserSession();

    // Signed out: sent to sign in.
    $this->get(($this->url)('/invoices'))->assertRedirect();

    // A viewer sees invoices but cannot create, issue, pay, credit, void or change settings.
    $this->actingAsMember($this->tenant, $biller);
    $this->get(($this->url)('/invoices'))->assertOk();
    $this->get(($this->url)("/invoices/{$invoice->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('abilities.recordPayment', false)->where('abilities.void', false)->where('abilities.send', false));
    $this->get(($this->url)('/invoices/create'))->assertForbidden();
    $this->post(($this->url)('/invoices'), ($this->payload)())->assertForbidden();
    $this->post(($this->url)("/invoices/{$invoice->id}/payments"), ['amount' => 1, 'method' => 'cash'])->assertForbidden();
    $this->post(($this->url)("/invoices/{$invoice->id}/void"), ['reason' => 'x'])->assertForbidden();
    $this->post(($this->url)("/invoices/{$invoice->id}/send"), [])->assertForbidden();
    $this->post(($this->url)("/invoices/{$invoice->id}/credit-notes"), ['reason' => 'x', 'lines' => []])->assertForbidden();
    $this->get(($this->url)('/settings'))->assertForbidden();
    $this->get(($this->url)('/recurring'))->assertForbidden();
    $this->get(($this->url)('/reports'))->assertForbidden();
    $this->get(($this->url)('/billable-time'))->assertForbidden();
});

it('narrows invoices to the records the role may reach and answers 404 outside the scope', function () {
    $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
        $this->owner,
        'Own invoices only',
        null,
        ['core.contacts.view', 'invoicing.invoices.view', 'invoicing.invoices.edit', 'invoicing.invoices.issue', 'invoicing.payments.record'],
        [
            'core.contacts.view' => 'all', 'invoicing.invoices.view' => 'own', 'invoicing.invoices.edit' => 'own',
            'invoicing.invoices.issue' => 'own', 'invoicing.payments.record' => 'own',
        ],
    ));
    $seller = $this->addMember($this->tenant, $role);
    $sellerMembership = $this->membershipOf($this->tenant, $seller);
    $foreign = ($this->makeIssued)();

    $this->actingAsMember($this->tenant, $seller);
    $this->post(($this->url)('/invoices'), ($this->payload)())->assertRedirect();
    $own = $this->inTenant($this->tenant, fn () => Invoice::query()->where('owner_id', $sellerMembership->id)->sole());

    $this->get(($this->url)('/invoices'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 1)->where('invoices.data.0.id', $own->id));
    $this->get(($this->url)("/invoices/{$own->id}"))->assertOk();
    $this->get(($this->url)("/invoices/{$foreign->id}"))->assertNotFound();
    $this->get(($this->url)("/invoices/{$foreign->id}/document"))->assertNotFound();
    $this->post(($this->url)("/invoices/{$foreign->id}/payments"), ['amount' => 1, 'method' => 'cash'])->assertNotFound();
    $this->post(($this->url)("/invoices/{$own->id}/issue"))->assertRedirect();
});

it('does not serve another workspace invoices, payments, credit notes, templates or tax rates', function () {
    $invoice = ($this->makeIssued)();
    [$payment, $note] = $this->inTenant($this->tenant, function () use ($invoice): array {
        $payment = app(PaymentManager::class)->record($invoice, ['amount' => 1_000, 'method' => 'cash']);
        $note = app(CreditNoteManager::class)->issue(
            $invoice, 'Credit', [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 500]],
        );

        return [$payment, $note];
    });
    $draft = $this->inTenant($this->tenant, fn () => app(InvoiceManager::class)->create(
        ['company_id' => $this->company->id, 'currency' => 'BDT'],
        [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 500]],
    ));
    $template = $this->inTenant($this->tenant, fn () => app(RecurringManager::class)->create([
        'company_id' => $this->company->id, 'name' => 'T', 'frequency' => 'monthly', 'every' => 1, 'currency' => 'BDT',
        'starts_on' => '2026-12-01',
    ], [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 500]]));
    $rate = $this->inTenant($this->tenant, fn () => TaxRate::query()->create(['name' => 'VAT', 'rate_bps' => 1500]));

    $this->assertNotFoundFromOtherTenant('GET', "/invoicing/invoices/{$invoice->id}", $this->tenant);
    $this->assertNotFoundFromOtherTenant('GET', "/invoicing/invoices/{$invoice->id}/document", $this->tenant);
    $this->assertNotFoundFromOtherTenant('GET', "/invoicing/invoices/{$invoice->id}/credit-notes/{$note->id}/document", $this->tenant);
    $this->assertNotFoundFromOtherTenant('POST', "/invoicing/invoices/{$invoice->id}/payments", $this->tenant, ['amount' => 10, 'method' => 'cash']);
    $this->assertNotFoundFromOtherTenant('POST', "/invoicing/invoices/{$invoice->id}/payments/{$payment->id}/reverse", $this->tenant, ['reason' => 'x']);
    $this->assertNotFoundFromOtherTenant('POST', "/invoicing/invoices/{$invoice->id}/credit-notes", $this->tenant, [
        'reason' => 'x', 'lines' => [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 1]],
    ]);
    $this->assertNotFoundFromOtherTenant('POST', "/invoicing/invoices/{$invoice->id}/send", $this->tenant, ['email' => 'a@b.test']);
    $this->assertNotFoundFromOtherTenant('POST', "/invoicing/invoices/{$invoice->id}/void", $this->tenant, ['reason' => 'x']);
    $this->assertNotFoundFromOtherTenant('PUT', "/invoicing/invoices/{$draft->id}", $this->tenant, ($this->payload)());
    $this->assertNotFoundFromOtherTenant('POST', "/invoicing/invoices/{$draft->id}/issue", $this->tenant);
    $this->assertNotFoundFromOtherTenant('PUT', "/invoicing/recurring/{$template->id}", $this->tenant, []);
    $this->assertNotFoundFromOtherTenant('PATCH', "/invoicing/recurring/{$template->id}/status", $this->tenant, ['status' => 'paused']);
    $this->assertNotFoundFromOtherTenant('PUT', "/invoicing/settings/tax-rates/{$rate->id}", $this->tenant, [
        'name' => 'x', 'rate_bps' => 1, 'is_default' => false, 'is_active' => true,
    ]);
    $this->assertNotFoundFromOtherTenant('DELETE', "/invoicing/invoices/{$draft->id}", $this->tenant);
});

it('refuses a nested payment or credit note that belongs to another invoice', function () {
    $first = ($this->makeIssued)();
    $second = ($this->makeIssued)();
    $payment = $this->inTenant($this->tenant, fn () => app(PaymentManager::class)->record($first, ['amount' => 1_000, 'method' => 'cash']));

    $this->actingAsOwner($this->tenant)
        ->post(($this->url)("/invoices/{$second->id}/payments/{$payment->id}/reverse"), ['reason' => 'x'])
        ->assertNotFound();
    $this->inTenant($this->tenant, fn () => expect($payment->refresh()->isReversed())->toBeFalse());
});

it('manages tax rates, settings and recurring templates through their pages', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/settings/tax-rates'), ['name' => 'VAT 15%', 'rate_bps' => 1500, 'is_default' => true, 'is_active' => true])->assertRedirect();
    $this->post(($this->url)('/settings/tax-rates'), ['name' => 'VAT 15%', 'rate_bps' => 1500, 'is_default' => false, 'is_active' => true])
        ->assertSessionHasErrors('name');
    $this->post(($this->url)('/settings/tax-rates'), ['name' => 'Zero', 'rate_bps' => 0, 'is_default' => true, 'is_active' => true])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        expect(TaxRate::query()->where('is_default', true)->sole()->name)->toBe('Zero');
    });

    $this->put(($this->url)('/settings'), [
        'invoice_prefix' => 'BILL-', 'credit_note_prefix' => 'CR-', 'number_padding' => 6, 'fiscal_year_numbering' => false,
        'fiscal_year_start_month' => 7, 'default_due_days' => 30, 'default_terms' => 'Net 30', 'default_notes' => null,
        'reminders_enabled' => true, 'reminder_before_days' => 2, 'reminder_overdue_every_days' => 5,
        'invoice_accepted_quotes' => true,
    ])->assertRedirect();
    $this->put(($this->url)('/settings'), ['invoice_prefix' => '<script>'])->assertSessionHasErrors('invoice_prefix');

    $this->get(($this->url)('/settings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('settings.invoicePrefix', 'BILL-')
            ->where('settings.defaultDueDays', 30)
            ->where('settings.remindersEnabled', true)
            ->has('taxRates', 2));

    // The new settings drive numbering and defaults.
    $this->post(($this->url)('/invoices'), ($this->payload)());
    $draft = $this->inTenant($this->tenant, fn () => Invoice::query()->sole());
    expect($draft->terms)->toBe('Net 30');
    $this->post(($this->url)("/invoices/{$draft->id}/issue"));
    $this->inTenant($this->tenant, function () use ($draft): void {
        $issued = $draft->refresh();
        expect($issued->number)->toBe('BILL-000001')
            ->and($issued->due_on->toDateString())->toBe($issued->issued_on->copy()->addDays(30)->toDateString());
    });

    $this->post(($this->url)('/recurring'), [
        'company_id' => $this->company->id, 'name' => 'Hosting', 'frequency' => 'monthly', 'every' => 1, 'currency' => 'BDT',
        'starts_on' => '2027-01-05', 'due_days' => 7, 'auto_issue' => false, 'auto_send' => false,
        'lines' => [['name' => 'Hosting', 'quantity_milli' => 1000, 'unit_price_amount' => 20_000, 'tax_bps' => 1500]],
    ])->assertRedirect();
    $template = $this->inTenant($this->tenant, fn () => RecurringTemplate::query()->sole());
    expect($template->next_run_on->toDateString())->toBe('2027-01-05');

    $this->get(($this->url)('/recurring'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('templates.data.0.totalAmount', 23_000)
            ->where('templates.data.0.status', 'active')
            ->where('canManage', true));
    $this->patch(($this->url)("/recurring/{$template->id}/status"), ['status' => 'paused'])->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect($template->refresh()->status->value)->toBe('paused'));
});

it('lists unbilled project time and creates a draft invoice from it', function () {
    $project = (string) Str::ulid();
    $this->inTenant($this->tenant, function () use ($project): void {
        BillableTime::query()->create([
            'project_id' => $project, 'time_entry_id' => (string) Str::ulid(),
            'minutes' => 120, 'rate_amount' => 15_000, 'currency' => 'BDT',
        ]);
    });
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/billable-time'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects.0.projectId', $project)
            ->where('projects.0.minutes', 120)
            ->where('projects.0.estimatedAmount', 30_000));

    $this->post(($this->url)('/billable-time/invoice'), [
        'project_id' => $project, 'currency' => 'BDT', 'company_id' => $this->company->id, 'label' => 'Sprint 4',
    ])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $invoice = Invoice::query()->sole();
        expect($invoice->total_amount)->toBe(30_000)
            ->and($invoice->status)->toBe(InvoiceStatus::Draft)
            ->and($invoice->lines->first()->name)->toBe('Sprint 4');
    });

    $this->post(($this->url)('/billable-time/invoice'), [
        'project_id' => $project, 'currency' => 'BDT', 'company_id' => $this->company->id,
    ])->assertSessionHasErrors('project_id');
});

it('refuses customers the actor cannot see in the shared directory', function () {
    $role = $this->createRole($this->tenant, 'Blind biller', ['invoicing.invoices.view', 'invoicing.invoices.edit']);
    $member = $this->addMember($this->tenant, $role);
    $contact = $this->inTenant($this->tenant, fn () => Contact::factory()->create());

    $this->actingAsMember($this->tenant, $member)
        ->post(($this->url)('/invoices'), ($this->payload)(['company_id' => null, 'contact_id' => $contact->id]))
        ->assertNotFound();
});
