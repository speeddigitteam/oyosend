<?php

declare(strict_types=1);

use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentMethod;
use App\Modules\Invoicing\Enums\RecurringFrequency;
use App\Modules\Invoicing\Enums\RecurringStatus;
use App\Modules\Invoicing\Models\AutomationActionReceipt;
use App\Modules\Invoicing\Models\BillableTime;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\CreditNoteLine;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\InvoicingSetting;
use App\Modules\Invoicing\Models\NumberSequence;
use App\Modules\Invoicing\Models\Payment;
use App\Modules\Invoicing\Models\RecurringTemplate;
use App\Modules\Invoicing\Models\ReminderLog;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

$invoice = fn (): Invoice => Invoice::query()->create(['currency' => 'BDT', 'status' => InvoiceStatus::Draft]);

it('isolates invoices between tenants', function () use ($invoice) {
    $this->assertTenantIsolated(Invoice::class, fn () => $invoice());
});

it('isolates invoice lines between tenants', function () use ($invoice) {
    $this->assertTenantIsolated(InvoiceLine::class, fn () => InvoiceLine::query()->create([
        'invoice_id' => $invoice()->id, 'position' => 1, 'name' => 'Line', 'unit' => 'unit', 'quantity_milli' => 1000,
        'unit_price_amount' => 100, 'gross_amount' => 100, 'net_amount' => 100, 'line_total_amount' => 100,
    ]));
});

it('isolates payments between tenants', function () use ($invoice) {
    $this->assertTenantIsolated(Payment::class, fn () => Payment::query()->create([
        'invoice_id' => $invoice()->id, 'amount' => 100, 'currency' => 'BDT', 'method' => PaymentMethod::Cash, 'paid_on' => '2026-01-01',
    ]));
});

it('isolates credit notes and their lines between tenants', function () use ($invoice) {
    $note = fn (): CreditNote => CreditNote::query()->create([
        'invoice_id' => $invoice()->id, 'number' => 'CN-'.Str::random(6), 'currency' => 'BDT', 'issued_on' => '2026-01-01',
        'reason' => 'Test', 'subtotal_amount' => 100, 'total_amount' => 100,
    ]);

    $this->assertTenantIsolated(CreditNote::class, fn () => $note());
    $this->assertTenantIsolated(CreditNoteLine::class, fn () => CreditNoteLine::query()->create([
        'credit_note_id' => $note()->id, 'position' => 1, 'name' => 'Line', 'unit' => 'unit', 'quantity_milli' => 1000,
        'unit_price_amount' => 100, 'gross_amount' => 100, 'net_amount' => 100, 'line_total_amount' => 100,
    ]));
});

it('isolates tax rates, settings and number sequences between tenants', function () {
    $this->assertTenantIsolated(TaxRate::class, fn () => TaxRate::query()->create(['name' => 'VAT', 'rate_bps' => 1500]));
    $this->assertTenantIsolated(InvoicingSetting::class, fn () => InvoicingSetting::query()->create([]));
    $this->assertTenantIsolated(NumberSequence::class, fn () => NumberSequence::query()->create(['kind' => 'invoice', 'period' => 0, 'last_number' => 3]));
});

it('isolates recurring templates, billable time, reminder logs and action receipts between tenants', function () use ($invoice) {
    $this->assertTenantIsolated(RecurringTemplate::class, fn () => RecurringTemplate::query()->create([
        'name' => 'Monthly', 'status' => RecurringStatus::Active, 'frequency' => RecurringFrequency::Monthly, 'currency' => 'BDT',
        'starts_on' => '2026-01-01', 'next_run_on' => '2026-01-01', 'lines' => [],
    ]));
    $this->assertTenantIsolated(BillableTime::class, fn () => BillableTime::query()->create([
        'project_id' => (string) Str::ulid(), 'time_entry_id' => (string) Str::ulid(), 'minutes' => 30, 'rate_amount' => 100, 'currency' => 'BDT',
    ]));
    $this->assertTenantIsolated(ReminderLog::class, fn () => ReminderLog::query()->create([
        'invoice_id' => $invoice()->id, 'reminder_key' => 'on_due', 'sent_to' => 'a@b.test', 'sent_on' => '2026-01-01',
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'invoicing.invoice.create_draft', 'idempotency_key' => Str::random(10), 'output' => [],
    ]));
});

it('allows the same document number in two workspaces but never twice in one', function () {
    $a = $this->createTenant();
    $b = $this->createTenant();
    $make = fn () => Invoice::query()->create(['currency' => 'BDT', 'status' => InvoiceStatus::Issued, 'number' => 'INV-0001']);

    $this->inTenant($a, fn () => $make());
    $this->inTenant($b, fn () => $make());

    expect(fn () => $this->inTenant($a, fn () => $make()))
        ->toThrow(UniqueConstraintViolationException::class);
});
