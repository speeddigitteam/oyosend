<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\Invoicing\Actions\CreditNoteManager;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\InvoicingSettings;
use App\Modules\Invoicing\Actions\NumberAllocator;
use App\Modules\Invoicing\Actions\PaymentManager;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentMethod;
use App\Modules\Invoicing\Events\CreditNoteIssued;
use App\Modules\Invoicing\Events\InvoicePaid;
use App\Modules\Invoicing\Events\PaymentReceived;
use App\Modules\Invoicing\Events\PaymentReversed;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = $this->createTenant('inv-pay', ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    $this->entitle($this->tenant, 'invoicing');
    $this->membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->contact = $this->inTenant($this->tenant, fn () => Contact::factory()->create(['first_name' => 'Rafi', 'last_name' => 'Khan']));

    // A BDT 1,000.00 invoice: 10.00 net + nothing, so 100_000 minor units.
    $this->issued = function (int $price = 100_000): Invoice {
        return $this->inTenant($this->tenant, function () use ($price): Invoice {
            $manager = app(InvoiceManager::class);

            return $manager->issue($manager->create([
                'contact_id' => $this->contact->id, 'currency' => 'BDT',
            ], [['name' => 'Service', 'quantity_milli' => 1000, 'unit_price_amount' => $price]], $this->membership));
        });
    };
    $this->pay = fn (Invoice $invoice, int $amount, array $extra = []) => $this->inTenant(
        $this->tenant,
        fn () => app(PaymentManager::class)->record($invoice, [
            'amount' => $amount, 'method' => PaymentMethod::BankTransfer, ...$extra,
        ], $this->membership),
    );
});

it('records partial payments, tracks the balance and settles the invoice', function () {
    Event::fake([PaymentReceived::class, InvoicePaid::class]);
    $invoice = ($this->issued)();

    $first = ($this->pay)($invoice, 40_000, ['reference' => 'TRX-1']);
    $this->inTenant($this->tenant, function () use ($invoice, $first): void {
        $fresh = Invoice::query()->findOrFail($invoice->id);
        expect($fresh->status)->toBe(InvoiceStatus::PartiallyPaid)
            ->and($fresh->paid_amount)->toBe(40_000)
            ->and($fresh->balance_amount)->toBe(60_000)
            ->and($first->currency)->toBe('BDT')
            ->and($first->recorded_by)->toBe($this->membership->id);
    });

    ($this->pay)($invoice, 60_000);

    $this->inTenant($this->tenant, function () use ($invoice): void {
        $fresh = Invoice::query()->findOrFail($invoice->id);
        expect($fresh->status)->toBe(InvoiceStatus::Paid)
            ->and($fresh->balance_amount)->toBe(0)
            ->and($fresh->paid_at)->not->toBeNull();
    });

    Event::assertDispatchedTimes(PaymentReceived::class, 2);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

it('refuses overpayment, non-positive amounts, future dates and payments on drafts', function () {
    $invoice = ($this->issued)();

    expect(fn () => ($this->pay)($invoice, 100_001))->toThrow(ValidationException::class)
        ->and(fn () => ($this->pay)($invoice, 0))->toThrow(ValidationException::class)
        ->and(fn () => ($this->pay)($invoice, 1_000, ['paid_on' => now('Asia/Dhaka')->addDays(2)->toDateString()]))
        ->toThrow(ValidationException::class);

    ($this->pay)($invoice, 100_000);
    expect(fn () => ($this->pay)($invoice, 1))->toThrow(ValidationException::class);

    $draft = $this->inTenant($this->tenant, fn () => app(InvoiceManager::class)->create([
        'contact_id' => $this->contact->id, 'currency' => 'BDT',
    ], [['name' => 'x', 'quantity_milli' => 1000, 'unit_price_amount' => 100]]));
    expect(fn () => ($this->pay)($draft, 100))->toThrow(ValidationException::class);

    $this->inTenant($this->tenant, fn () => expect(Payment::query()->count())->toBe(1));
});

it('records a payment once per idempotency key', function () {
    $invoice = ($this->issued)();
    $other = ($this->issued)(50_000);

    $a = ($this->pay)($invoice, 30_000, ['idempotency_key' => 'form-abc']);
    $b = ($this->pay)($invoice, 30_000, ['idempotency_key' => 'form-abc']);

    expect($b->id)->toBe($a->id);
    $this->inTenant($this->tenant, fn () => expect(Payment::query()->count())->toBe(1)
        ->and(Invoice::query()->findOrFail($invoice->id)->paid_amount)->toBe(30_000));

    // The same key with a different amount, or against another invoice, is refused rather than ignored.
    expect(fn () => ($this->pay)($invoice, 31_000, ['idempotency_key' => 'form-abc']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->pay)($other, 30_000, ['idempotency_key' => 'form-abc']))->toThrow(ValidationException::class);
});

it('reverses a payment with a reason, reopens the invoice and keeps the history', function () {
    Event::fake([PaymentReversed::class]);
    $invoice = ($this->issued)();
    $payment = ($this->pay)($invoice, 100_000);

    $this->inTenant($this->tenant, function () use ($invoice, $payment): void {
        expect(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Paid);

        expect(fn () => app(PaymentManager::class)->reverse($payment, '  ', $this->membership))
            ->toThrow(ValidationException::class);

        $reversed = app(PaymentManager::class)->reverse($payment, 'Cheque bounced', $this->membership);
        $fresh = Invoice::query()->findOrFail($invoice->id);

        expect($reversed->isReversed())->toBeTrue()
            ->and($reversed->reversal_reason)->toBe('Cheque bounced')
            ->and($fresh->status)->toBe(InvoiceStatus::Issued)
            ->and($fresh->paid_amount)->toBe(0)
            ->and($fresh->balance_amount)->toBe(100_000)
            ->and($fresh->paid_at)->toBeNull()
            ->and(Payment::query()->count())->toBe(1)
            // Reversing again changes nothing.
            ->and(app(PaymentManager::class)->reverse($payment, 'again', $this->membership)->reversed_at->equalTo($reversed->reversed_at))->toBeTrue();

        // The balance can be paid again and the audit trail names the reversal.
        app(PaymentManager::class)->record($fresh, ['amount' => 100_000, 'method' => 'cash'], $this->membership);
        expect(Invoice::query()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Paid);
    });

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
    expect($this->auditTrail($this->tenant, 'invoicing.payment.reversed'))->toHaveCount(1);
});

it('issues a credit note that reduces the balance and cannot exceed it', function () {
    Event::fake([CreditNoteIssued::class, InvoicePaid::class]);
    $invoice = ($this->issued)();
    ($this->pay)($invoice, 30_000);

    $this->inTenant($this->tenant, function () use ($invoice): void {
        $notes = app(CreditNoteManager::class);
        $line = ['name' => 'Goodwill', 'quantity_milli' => 1000, 'unit_price_amount' => 20_000];

        expect(fn () => $notes->issue($invoice, 'too much', [[...$line, 'unit_price_amount' => 70_001]]))
            ->toThrow(ValidationException::class)
            ->and(fn () => $notes->issue($invoice, '', [$line]))->toThrow(ValidationException::class);

        $note = $notes->issue($invoice, 'Late delivery', [$line], $this->membership);
        $fresh = Invoice::query()->findOrFail($invoice->id);

        expect($note->number)->toBe('CN-0001')
            ->and($note->total_amount)->toBe(20_000)
            ->and($fresh->credited_amount)->toBe(20_000)
            ->and($fresh->balance_amount)->toBe(50_000)
            ->and($fresh->status)->toBe(InvoiceStatus::PartiallyPaid);

        // Crediting the rest closes the invoice; nothing more can be credited.
        $second = $notes->issue($invoice, 'Remainder', [[...$line, 'unit_price_amount' => 50_000]]);
        $closed = Invoice::query()->findOrFail($invoice->id);

        expect($second->number)->toBe('CN-0002')
            ->and($closed->status)->toBe(InvoiceStatus::Paid)
            ->and($closed->balance_amount)->toBe(0)
            ->and(fn () => $notes->issue($invoice, 'More', [$line]))->toThrow(ValidationException::class);
    });

    Event::assertDispatchedTimes(CreditNoteIssued::class, 2);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

it('keeps credit note lines immutable and tax-exact', function () {
    $invoice = $this->inTenant($this->tenant, function (): Invoice {
        $manager = app(InvoiceManager::class);

        return $manager->issue($manager->create(['contact_id' => $this->contact->id, 'currency' => 'BDT'], [
            ['name' => 'Taxed', 'quantity_milli' => 1000, 'unit_price_amount' => 10_000, 'tax_bps' => 1500],
        ]));
    });

    $this->inTenant($this->tenant, function () use ($invoice): void {
        $note = app(CreditNoteManager::class)->issue($invoice, 'Half returned', [
            ['name' => 'Taxed', 'quantity_milli' => 500, 'unit_price_amount' => 10_000, 'tax_bps' => 1500],
        ]);

        expect($note->subtotal_amount)->toBe(5_000)
            ->and($note->tax_amount)->toBe(750)
            ->and($note->total_amount)->toBe(5_750)
            ->and(fn () => $note->lines->first()->update(['name' => 'x']))->toThrow(LogicException::class);
    });
});

it('allocates gap-free document numbers and hands one back when the transaction fails', function () {
    $this->inTenant($this->tenant, function (): void {
        $manager = app(InvoiceManager::class);
        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $manager->issue($manager->create(
                ['contact_id' => $this->contact->id, 'currency' => 'BDT'],
                [['name' => 'Service', 'quantity_milli' => 1000, 'unit_price_amount' => 100]],
            ))->number;
        }

        expect($numbers)->toBe(['INV-0001', 'INV-0002', 'INV-0003', 'INV-0004', 'INV-0005']);

        // A failure after allocating rolls the counter back: the next document takes the number.
        $settings = app(InvoicingSettings::class)->current();
        try {
            DB::transaction(function () use ($settings): void {
                app(NumberAllocator::class)->next(NumberAllocator::INVOICE, now()->toImmutable(), $settings);
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $next = $manager->issue($manager->create(
            ['contact_id' => $this->contact->id, 'currency' => 'BDT'],
            [['name' => 'Service', 'quantity_milli' => 1000, 'unit_price_amount' => 100]],
        ));
        expect($next->number)->toBe('INV-0006');
    });
});
