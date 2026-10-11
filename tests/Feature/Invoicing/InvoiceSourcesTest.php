<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\QuoteManager;
use App\Modules\CRM\Enums\CatalogItemType;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Events\QuoteAccepted;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Quote;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\InvoiceSources;
use App\Modules\Invoicing\Actions\InvoicingSettings;
use App\Modules\Invoicing\Automation\Actions\CreateDraftInvoiceAction;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\AutomationActionReceipt;
use App\Modules\Invoicing\Models\BillableTime;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Projects\Events\TimeEntryApproved;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = $this->createTenant('inv-src', ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    $this->entitle($this->tenant, 'invoicing', 'crm', 'projects');
    $this->membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->contact = $this->inTenant($this->tenant, fn () => Contact::factory()->create(['first_name' => 'Nadia', 'last_name' => 'Islam']));

    $this->acceptQuote = function (): Quote {
        return $this->inTenant($this->tenant, function (): Quote {
            $product = Product::query()->create([
                'type' => CatalogItemType::Service, 'sku' => 'IMPL', 'name' => 'Implementation', 'unit' => 'hour',
                'currency' => 'BDT', 'unit_price_amount' => 25_000, 'tax_bps' => 1500, 'is_active' => true,
            ]);
            $quotes = app(QuoteManager::class);
            $quote = $quotes->create([
                'contact_id' => $this->contact->id, 'kind' => QuoteKind::Quotation->value, 'title' => 'Proposal',
                'currency' => 'BDT', 'probability_percent' => 60,
            ], [['product_id' => $product->id, 'quantity_milli' => 1500, 'discount_amount' => 2500]], $this->membership);
            $quotes->send($quote);
            $quotes->accept($quote);

            return $quote->refresh();
        });
    };
});

it('creates one draft invoice from an accepted quotation using only the event payload', function () {
    $quote = ($this->acceptQuote)();

    $this->inTenant($this->tenant, function () use ($quote): void {
        $invoice = Invoice::query()->where('source_key', 'crm_quote:'.$quote->id)->sole();

        expect($invoice->status)->toBe(InvoiceStatus::Draft)
            ->and($invoice->source_type)->toBe('crm_quote')
            ->and($invoice->source_id)->toBe($quote->id)
            ->and($invoice->reference)->toBe($quote->number)
            ->and($invoice->contact_id)->toBe($this->contact->id)
            ->and($invoice->currency)->toBe('BDT')
            // The totals are recomputed here, and agree to the minor unit with the quotation.
            ->and($invoice->total_amount)->toBe($quote->total_amount)
            ->and($invoice->tax_amount)->toBe($quote->tax_amount)
            ->and($invoice->lines)->toHaveCount(1)
            ->and($invoice->lines->first()->name)->toBe('Implementation')
            ->and($invoice->lines->first()->tax_bps)->toBe(1500)
            // No signed-in member in this call, so the hand-off draft has no owner (visible to all-scope holders).
            ->and($invoice->owner_id)->toBeNull()
            ->and($invoice->number)->toBeNull();
    });
});

it('is idempotent when the same quotation event is delivered again', function () {
    $quote = ($this->acceptQuote)();

    $this->inTenant($this->tenant, function () use ($quote): void {
        $order = $quote->salesOrder;
        event(new QuoteAccepted($quote, $order));
        event(new QuoteAccepted($quote, $order));

        expect(Invoice::query()->where('source_key', 'crm_quote:'.$quote->id)->count())->toBe(1);
    });
});

it('does nothing when the workspace turned quote invoicing off or is not entitled', function () {
    $this->inTenant($this->tenant, fn () => app(InvoicingSettings::class)->update(['invoice_accepted_quotes' => false]));
    ($this->acceptQuote)();
    $this->inTenant($this->tenant, fn () => expect(Invoice::query()->count())->toBe(0));

    $other = $this->createTenant('inv-src-other');
    $this->entitle($other, 'crm');
    $contact = $this->inTenant($other, fn () => Contact::factory()->create());
    $membership = $this->membershipOf($other, $this->ownerOf($other));

    $this->inTenant($other, function () use ($contact, $membership): void {
        $quotes = app(QuoteManager::class);
        $quote = $quotes->create([
            'contact_id' => $contact->id, 'kind' => QuoteKind::Quotation->value, 'title' => 'No invoicing',
            'currency' => 'BDT', 'probability_percent' => 50,
        ], [['name' => 'Custom', 'quantity_milli' => 1000, 'unit_price_amount' => 500]], $membership);
        $quotes->send($quote);
        $quotes->accept($quote);

        expect(Invoice::query()->count())->toBe(0);
    });
});

it('never turns a bad quotation payload into a failed acceptance', function () {
    $this->inTenant($this->tenant, function (): void {
        expect(fn () => app(InvoiceSources::class)->fromAcceptedQuote(['quote_id' => 'q1', 'lines' => []]))
            ->toThrow(ValidationException::class);
    });
});

it('remembers approved billable time and invoices it once', function () {
    $project = (string) Str::ulid();
    $dispatch = fn (int $minutes, int $rate, bool $billable = true, ?string $entry = null) => $this->inTenant(
        $this->tenant,
        fn () => event(new TimeEntryApproved($entry ?? (string) Str::ulid(), $project, (string) Str::ulid(), $minutes, $billable, $rate, 0, 'BDT')),
    );

    $dispatch(90, 20_000);
    $dispatch(30, 20_000);
    $dispatch(60, 30_000);
    $dispatch(45, 20_000, billable: false);
    $dispatch(60, 0);
    $duplicate = (string) Str::ulid();
    $dispatch(15, 20_000, entry: $duplicate);
    $dispatch(15, 20_000, entry: $duplicate);

    $this->inTenant($this->tenant, function () use ($project): void {
        expect(BillableTime::query()->count())->toBe(4);

        $invoice = app(InvoiceSources::class)->fromBillableTime($project, $this->contact->id, null, 'bdt', 'Website build', $this->membership);
        $lines = $invoice->lines->sortBy('unit_price_amount')->values();

        // 90 + 30 + 15 = 135 min at 200.00/h = 2.25 h = 450.00; 60 min at 300.00/h = 300.00
        expect($invoice->status)->toBe(InvoiceStatus::Draft)
            ->and($invoice->source_type)->toBe('project')
            ->and($invoice->source_id)->toBe($project)
            ->and($lines)->toHaveCount(2)
            ->and($lines[0]->quantity_milli)->toBe(2250)
            ->and($lines[0]->line_total_amount)->toBe(45_000)
            ->and($lines[1]->quantity_milli)->toBe(1000)
            ->and($lines[1]->line_total_amount)->toBe(30_000)
            ->and($invoice->total_amount)->toBe(75_000)
            ->and(BillableTime::query()->whereNull('invoice_id')->count())->toBe(0);

        // Nothing is left to bill, so a second run cannot invoice the same time again.
        expect(fn () => app(InvoiceSources::class)->fromBillableTime($project, $this->contact->id, null, 'BDT'))
            ->toThrow(ValidationException::class);

        // Deleting the draft releases the time for a new invoice.
        app(InvoiceManager::class)->delete($invoice);
        expect(BillableTime::query()->whereNull('invoice_id')->count())->toBe(4);
    });
});

it('creates a draft invoice from an automation action exactly once per idempotency key', function () {
    $this->inTenant($this->tenant, function (): void {
        $action = app(CreateDraftInvoiceAction::class);
        $input = [
            'contact_id' => $this->contact->id,
            'currency' => 'BDT',
            'title' => 'Automated',
            'due_days' => 10,
            'lines' => [['name' => 'Retainer', 'quantity_milli' => 1000, 'unit_price_amount' => 90_000, 'tax_bps' => 500]],
        ];

        $first = $action->execute($input, 'workflow-1:step-2');
        $second = $action->execute($input, 'workflow-1:step-2');
        $other = $action->execute($input, 'workflow-1:step-3');

        expect($second)->toEqual($first)
            ->and($first['status'])->toBe('draft')
            ->and($first['total_amount'])->toBe(94_500)
            ->and($other['invoice_id'])->not->toBe($first['invoice_id'])
            ->and(Invoice::query()->count())->toBe(2)
            ->and(AutomationActionReceipt::query()->count())->toBe(2)
            ->and(Invoice::query()->findOrFail($first['invoice_id'])->due_on->toDateString())
            ->toBe(now('Asia/Dhaka')->addDays(10)->toDateString());
    });
});

it('registers the invoicing triggers and the draft action in the automation registry', function () {
    $registry = app(AutomationRegistry::class);

    foreach ([
        'invoicing.invoice.issued', 'invoicing.invoice.paid', 'invoicing.payment.received',
        'invoicing.credit_note.issued', 'invoicing.invoice.overdue',
    ] as $key) {
        expect($registry->trigger($key))->not->toBeNull();
    }

    expect($registry->action('invoicing.invoice.create_draft'))->toBe(CreateDraftInvoiceAction::class)
        ->and($registry->action('invoicing.payment.record'))->toBeNull();
});
