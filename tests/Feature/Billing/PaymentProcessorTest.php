<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Gateways\PaymentRedirect;
use App\Core\Billing\Gateways\VerifiedPayment;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Mail\InvoiceIssuedMail;
use App\Core\Billing\Mail\PaymentReceivedMail;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\PaymentProcessor;
use App\Core\Billing\SubscriptionManager;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * PaymentProcessor, called the way the controllers call it: inside the
 * workspace's context. The gateway is the test double from
 * tests/Support/Fixtures - it never leaves the process, and each test decides
 * what the "provider" answers when it is asked about a payment.
 *
 * Every test starts with `acme` owing its first invoice: Starter, BDT 1,500.00.
 */
beforeEach(function () {
    // Kept, so the tests about delivery can put the real mailer back.
    $this->mailer = app('mail.manager');

    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->owner = User::factory()->create(['name' => 'Ayesha Rahman', 'email' => 'ayesha@acme.example']);
    $this->acme = $this->createTenant('acme', ['name' => 'Acme'], $this->owner);
    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000, 'yearly_amount' => 1500000]);
    $this->business = $this->createPlan(['key' => 'business', 'name' => 'Business', 'monthly_amount' => 450000, 'yearly_amount' => 4500000]);

    $this->gateway = $this->useTestGateway();

    $this->subscribe($this->acme, $this->starter, withTrial: false);
    $this->invoice = $this->openInvoiceOf($this->acme);

    /** Call the payment processor as the workspace. */
    $this->payments = fn (Closure $callback): mixed => $this->inTenant(
        $this->acme,
        fn (): mixed => $callback(app(PaymentProcessor::class)),
    );

    /** Start paying at the gateway; answers the payment that was opened. */
    $this->begin = function (?Invoice $invoice = null): Payment {
        ($this->payments)(fn (PaymentProcessor $payments): PaymentRedirect => $payments->begin($invoice ?? $this->invoice, 'testpay', $this->owner));

        return $this->paymentsOf($this->acme)->last();
    };

    $this->confirm = fn (Payment $payment, array $payload = []): Payment => ($this->payments)(
        fn (PaymentProcessor $payments): Payment => $payments->confirm($payment, $payload),
    );

    /*
     * Who a queued mail is addressed to. Not `hasTo()`: that builds the
     * envelope, whose subject names the workspace - and these assertions run
     * outside it.
     */
    $this->recipients = fn ($mail): array => array_column($mail->to, 'address');

    /** The refusal of a request: field => message. */
    $this->refusal = function (Closure $callback): array {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return array_map(static fn (array $messages): string => $messages[0], $exception->errors());
        }

        $this->fail('The request was not refused.');
    };
});

describe('starting a payment', function () {
    it('opens a pending payment for the invoice and sends the payer to the gateway', function () {
        $redirect = ($this->payments)(fn (PaymentProcessor $payments) => $payments->begin($this->invoice, 'testpay', $this->owner));

        $payment = $this->paymentsOf($this->acme)->sole();

        expect($redirect)->toBeInstanceOf(PaymentRedirect::class)
            ->and($redirect->url)->toBe('https://pay.test/checkout/'.$payment->reference)
            ->and($payment->tenant_id)->toBe($this->acme->id)
            ->and($payment->invoice_id)->toBe($this->invoice->id)
            ->and($payment->gateway)->toBe('testpay')
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->amount)->toBe(150000)
            ->and($payment->currency)->toBe('BDT')
            // Our own reference: a ULID, which the return route and every gateway's length limit accept.
            ->and($payment->reference)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($payment->reference)->not->toBe($payment->id)
            ->and($payment->gateway_reference)->toBeNull()
            ->and($payment->method)->toBeNull()
            ->and($payment->paid_at)->toBeNull()
            ->and($payment->failed_at)->toBeNull()
            ->and($payment->recorded_by)->toBeNull()
            // What the gateway said when the payment was opened.
            ->and($payment->details)->toEqual(['session' => 'sess-'.$payment->reference]);

        // Nothing has been paid: a redirect is not money.
        expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);

        $entry = $this->auditTrail($this->acme, 'billing.payment_started')->sole();

        expect($entry->subject_type)->toBe('payment')
            ->and($entry->subject_id)->toBe($payment->id)
            ->and($entry->subject_label)->toBe($payment->reference)
            ->and($entry->properties)->toEqual(['gateway' => 'testpay', 'invoice' => $this->invoice->number]);

        Mail::assertNotQueued(PaymentReceivedMail::class);
    });

    it('tells the gateway what is paid for, by whom, and where to send the payer and its notification', function () {
        $this->acme->update([
            'legal_name' => 'Acme Trading Limited',
            'email' => 'accounts@acme.example',
            'phone' => '+8801700000000',
            'address' => 'House 12, Road 5, Dhanmondi',
            'city' => 'Dhaka',
            'postal_code' => '1205',
            'country' => 'BD',
        ]);

        $payment = ($this->begin)();
        $request = $this->gateway->requests[0];

        expect($this->gateway->requests)->toHaveCount(1)
            ->and($request->description)->toBe('Invoice '.$this->invoice->number)
            ->and($request->customerName)->toBe('Acme Trading Limited')
            ->and($request->customerEmail)->toBe('accounts@acme.example')
            ->and($request->customerPhone)->toBe('+8801700000000')
            ->and($request->address)->toBe('House 12, Road 5, Dhanmondi')
            ->and($request->city)->toBe('Dhaka')
            ->and($request->postalCode)->toBe('1205')
            ->and($request->country)->toBe('BD')
            // Both on the workspace's own host: the tenant is resolved from it when the gateway calls back.
            ->and($request->returnUrl)->toBe("http://acme.localhost/billing/payments/{$payment->reference}/return")
            ->and($request->notifyUrl)->toBe('http://acme.localhost/billing/gateways/testpay/notify');
    });

    it('uses the workspace name and the payer\'s own address when the company profile is empty', function () {
        $accountant = $this->addMember($this->acme, Role::ADMIN, User::factory()->create(['email' => 'accountant@acme.example']));

        ($this->payments)(fn (PaymentProcessor $payments) => $payments->begin($this->invoice, 'testpay', $accountant));

        $request = $this->gateway->requests[0];

        expect($request->customerName)->toBe('Acme')
            // The person paying, not the owner.
            ->and($request->customerEmail)->toBe('accountant@acme.example')
            ->and($request->customerPhone)->toBeNull()
            ->and($request->address)->toBeNull()
            ->and($request->city)->toBeNull()
            ->and($request->postalCode)->toBeNull()
            ->and($request->country)->toBeNull();
    });

    it('gives every attempt a reference of its own', function () {
        $first = ($this->begin)();
        $second = ($this->begin)();

        expect($this->paymentsOf($this->acme))->toHaveCount(2)
            ->and($first->reference)->not->toBe($second->reference)
            ->and($first->status)->toBe(PaymentStatus::Pending)
            ->and($second->status)->toBe(PaymentStatus::Pending);
    });

    it('refuses a payment method that cannot be used', function (Closure $arrange, string $key) {
        $arrange($this->gateway);

        expect(($this->refusal)(fn () => ($this->payments)(fn (PaymentProcessor $payments) => $payments->begin($this->invoice, $key, $this->owner))))
            ->toBe(['gateway' => 'This payment method is not available.']);

        expect($this->paymentsOf($this->acme))->toBeEmpty()
            ->and($this->gateway->requests)->toBe([])
            ->and($this->auditTrail($this->acme, 'billing.payment_started'))->toBeEmpty();
    })->with([
        'a gateway that does not exist' => [fn () => null, 'paypal'],
        'the offline "gateway", which is for operators' => [fn () => null, 'offline'],
        'a gateway without credentials' => [fn ($gateway) => $gateway->configured = false, 'testpay'],
        'a gateway that does not take the currency' => [fn ($gateway) => $gateway->currencies = ['USD'], 'testpay'],
    ]);

    it('refuses an invoice that is not waiting for payment', function (Closure $arrange) {
        $invoice = $arrange->call($this);

        expect(($this->refusal)(fn () => ($this->payments)(fn (PaymentProcessor $payments) => $payments->begin($invoice, 'testpay', $this->owner))))
            ->toBe(['invoice' => 'This invoice is not waiting for payment.']);

        expect($this->paymentsOf($this->acme)->where('gateway', 'testpay'))->toBeEmpty()
            ->and($this->gateway->requests)->toBe([]);
    })->with([
        'paid' => [function (): Invoice {
            $this->payOpenInvoice($this->acme);

            return $this->invoice->refresh();
        }],
        'withdrawn' => [function (): Invoice {
            $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($this->invoice));

            return $this->invoice->refresh();
        }],
        'for nothing' => [function (): Invoice {
            $this->invoice->update(['credit_amount' => 150000, 'total_amount' => 0]);

            return $this->invoice;
        }],
    ]);

    it('keeps the failed attempt on record when the gateway cannot open the payment', function () {
        Exceptions::fake();

        $this->gateway->initiationFailure = new GatewayException('TestPay refused the session: store is inactive.');

        expect(($this->refusal)(fn () => ($this->begin)()))
            ->toBe(['gateway' => 'The payment could not be started. Please try again, or choose another payment method.']);

        $payment = $this->paymentsOf($this->acme)->sole();

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failed_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($payment->failure_reason)->toBe('TestPay refused the session: store is inactive.')
            ->and($payment->paid_at)->toBeNull()
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->auditTrail($this->acme, 'billing.payment_started'))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'billing.payment_failed')->sole()->properties)
            ->toEqual(['status' => 'failed', 'reason' => 'TestPay refused the session: store is inactive.']);

        // For the operator: the provider's own words go to the error log.
        Exceptions::assertReported(GatewayException::class);

        // The payer can simply try again.
        $this->gateway->initiationFailure = null;

        expect(($this->begin)()->status)->toBe(PaymentStatus::Pending);
    });

    it('says what is missing when the gateway needs company details the workspace has not filled in', function () {
        $this->gateway->initiationFailure = GatewayException::missingBillingDetails('testpay');

        expect(($this->refusal)(fn () => ($this->begin)()))->toBe([
            'gateway' => 'Add the company phone number, address, city, postal code and country in the workspace settings before paying online.',
        ]);

        expect($this->paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Failed);
    });

    it('cuts a failure reason that is longer than the column', function () {
        $this->gateway->initiationFailure = new GatewayException(str_repeat('The provider is unwell. ', 40));

        ($this->refusal)(fn () => ($this->begin)());

        $reason = $this->paymentsOf($this->acme)->sole()->failure_reason;

        expect(mb_strlen($reason))->toBeGreaterThan(200)->toBeLessThanOrEqual(250)
            ->and($reason)->toStartWith('The provider is unwell. The provider is unwell.');
    });

    it('needs a workspace', function () {
        expect(fn () => app(PaymentProcessor::class)->begin($this->invoice, 'testpay', $this->owner))
            ->toThrow(MissingTenantContextException::class);

        expect(Payment::withoutTenancy()->count())->toBe(0);
    });
});

describe('confirming a payment', function () {
    beforeEach(function () {
        $this->payment = ($this->begin)();
    });

    it('settles the invoice when the gateway says it was paid in full', function () {
        $this->travel(5)->minutes();

        $confirmed = ($this->confirm)($this->payment, ['val_id' => 'abc', 'status' => 'whatever the browser says']);
        $payment = $this->paymentsOf($this->acme)->sole();

        expect($confirmed->id)->toBe($payment->id)
            ->and($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->gateway_reference)->toBe('txn-'.$payment->reference)
            ->and($payment->method)->toBe('CARD')
            ->and($payment->paid_at->toDateTimeString())->toBe('2026-03-10 09:05:00')
            ->and($payment->failed_at)->toBeNull()
            ->and($payment->failure_reason)->toBeNull()
            // The gateway gave nothing new to keep: what it said at the start stays.
            ->and($payment->details)->toEqual(['session' => 'sess-'.$payment->reference])
            // The gateway was handed what arrived, to find the transaction by.
            ->and($this->gateway->verifications)->toBe([['val_id' => 'abc', 'status' => 'whatever the browser says']]);

        $invoice = $this->invoice->refresh();
        $subscription = $this->subscriptionOf($this->acme);

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->paid_at->toDateTimeString())->toBe('2026-03-10 09:05:00')
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00');

        $received = $this->auditTrail($this->acme, 'billing.payment_received')->sole();

        expect($received->subject_type)->toBe('payment')
            ->and($received->subject_id)->toBe($payment->id)
            ->and($received->properties)->toEqual(['gateway' => 'testpay', 'amount' => 150000, 'currency' => 'BDT'])
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid')->sole()->subject_id)->toBe($invoice->id)
            ->and($this->auditTrail($this->acme, 'billing.subscription_activated'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied'))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'billing.payment_failed'))->toBeEmpty();

        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('keeps what the gateway says about the transaction', function () {
        $this->gateway->answer = new VerifiedPayment(
            PaymentStatus::Succeeded,
            150000,
            'bdt',
            'BANK-7781',
            'BKASH',
            details: ['val_id' => 'v-1', 'card_brand' => 'MOBILEBANKING'],
        );

        $payment = ($this->confirm)($this->payment);

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->gateway_reference)->toBe('BANK-7781')
            ->and($payment->method)->toBe('BKASH')
            ->and($payment->refresh()->details)->toEqual(['val_id' => 'v-1', 'card_brand' => 'MOBILEBANKING'])
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('marks the payment failed when the gateway says so, and the invoice stays payable', function () {
        $this->gateway->willFail('Declined by the bank.');

        $payment = ($this->confirm)($this->payment);

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failed_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($payment->failure_reason)->toBe('Declined by the bank.')
            ->and($payment->paid_at)->toBeNull()
            ->and($payment->gateway_reference)->toBeNull()
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed')->sole()->properties)
            ->toEqual(['status' => 'failed', 'reason' => 'Declined by the bank.'])
            ->and($this->auditTrail($this->acme, 'billing.payment_received'))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toBeEmpty();

        Mail::assertNotQueued(PaymentReceivedMail::class);

        // Another attempt, with another card, goes through.
        $this->gateway->answer = null;

        $second = ($this->confirm)(($this->begin)());

        expect($second->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($this->paymentsOf($this->acme)->pluck('status')->all())->toBe([PaymentStatus::Failed, PaymentStatus::Succeeded]);
    });

    it('marks the payment canceled when the payer gave up', function () {
        $this->gateway->willCancel();

        $payment = ($this->confirm)($this->payment);

        expect($payment->status)->toBe(PaymentStatus::Canceled)
            ->and($payment->failed_at)->not->toBeNull()
            ->and($payment->failure_reason)->toBeNull()
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed')->sole()->properties)
            ->toEqual(['status' => 'canceled', 'reason' => null]);

        Mail::assertNotQueued(PaymentReceivedMail::class);
    });

    it('leaves the payment pending while the gateway has no answer yet', function () {
        $this->gateway->answer = new VerifiedPayment(PaymentStatus::Pending);

        $before = $this->payment->getAttributes();
        $payment = ($this->confirm)($this->payment);

        expect($payment->status)->toBe(PaymentStatus::Pending)
            ->and($this->paymentsOf($this->acme)->sole()->getAttributes())->toBe($before)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed'))->toBeEmpty();
    });

    it('notes why a payment is held, keeps it pending, and settles it when the gateway releases it', function () {
        $this->gateway->answer = new VerifiedPayment(
            PaymentStatus::Pending,
            150000,
            'BDT',
            'BANK-1',
            'VISA',
            'Held by the gateway for a risk review.',
            ['risk_level' => '1', 'risk_title' => 'Not Safe'],
        );

        $payment = ($this->confirm)($this->payment);

        expect($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->failure_reason)->toBe('Held by the gateway for a risk review.')
            ->and($payment->refresh()->details)->toEqual(['risk_level' => '1', 'risk_title' => 'Not Safe'])
            ->and($payment->paid_at)->toBeNull()
            ->and($payment->failed_at)->toBeNull()
            // Held is not paid.
            ->and($payment->gateway_reference)->toBeNull()
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed'))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'billing.payment_received'))->toBeEmpty();

        Mail::assertNotQueued(PaymentReceivedMail::class);

        $this->gateway->answer = null;

        $payment = ($this->confirm)($payment);

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->failure_reason)->toBeNull()
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('fails a payment the gateway confirmed for another amount or currency', function (?int $amount, ?string $currency) {
        $this->gateway->answer = new VerifiedPayment(PaymentStatus::Succeeded, $amount, $currency, 'txn-other', 'CARD');

        $payment = ($this->confirm)($this->payment);

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_reason)->toBe('The gateway confirmed a different amount or currency than the invoice.')
            ->and($payment->paid_at)->toBeNull()
            ->and($payment->gateway_reference)->toBeNull()
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toBeEmpty();

        Mail::assertNotQueued(PaymentReceivedMail::class);
    })->with([
        'one paisa short' => [149999, 'BDT'],
        'one paisa over' => [150001, 'BDT'],
        'a hundred times less' => [1500, 'BDT'],
        'nothing' => [0, 'BDT'],
        'no amount at all' => [null, 'BDT'],
        'the same number in another currency' => [150000, 'USD'],
        'no currency at all' => [150000, null],
        'no currency, as an empty string' => [150000, ''],
    ]);

    it('accepts the currency in whatever case the gateway writes it', function () {
        $this->gateway->willConfirm(150000, 'bdt', 'txn-lower');

        expect(($this->confirm)($this->payment)->status)->toBe(PaymentStatus::Succeeded);
    });

    it('stays pending, without an error, when the gateway cannot be reached', function () {
        Exceptions::fake();

        $this->gateway->verificationFailure = new GatewayException('TestPay could not be reached.');

        $before = $this->payment->getAttributes();
        $payment = ($this->confirm)($this->payment, ['val_id' => 'abc']);

        expect($payment->status)->toBe(PaymentStatus::Pending)
            ->and($this->paymentsOf($this->acme)->sole()->getAttributes())->toBe($before)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed'))->toBeEmpty();

        Exceptions::assertReported(GatewayException::class);

        // The gateway notifies again, or the payer comes back: then it is settled.
        $this->gateway->verificationFailure = null;

        expect(($this->confirm)($payment)->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('does not ask a gateway that is no longer configured, and decides nothing', function () {
        $this->gateway->configured = false;

        $payment = ($this->confirm)($this->payment, ['val_id' => 'abc']);

        expect($payment->status)->toBe(PaymentStatus::Pending)
            ->and($this->gateway->verifications)->toBe([])
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
    });

    it('decides nothing about a payment of a gateway this build no longer has', function () {
        $orphan = $this->inTenant($this->acme, fn (): Payment => Payment::query()->create([
            'invoice_id' => $this->invoice->id,
            'gateway' => 'retiredpay',
            'status' => PaymentStatus::Pending,
            'currency' => 'BDT',
            'amount' => 150000,
            'reference' => '01JNZ8Q6X3V9T4M2K7H5C1B0AE',
        ]));

        expect(($this->confirm)($orphan)->status)->toBe(PaymentStatus::Pending)
            ->and($this->gateway->verifications)->toBe([]);
    });
});

describe('a payment that has reached a final state', function () {
    it('is never changed by a later confirmation', function (Closure $first, PaymentStatus $final, InvoiceStatus $invoice) {
        $payment = ($this->begin)();

        $first($this->gateway);

        $payment = ($this->confirm)($payment);
        $stored = $this->paymentsOf($this->acme)->sole()->getAttributes();

        expect($payment->status)->toBe($final);

        $audited = $this->auditTrail($this->acme)->count();
        $mailed = Mail::queued(PaymentReceivedMail::class)->count();

        // Whatever the gateway says from now on - or is made to say.
        foreach ([
            new VerifiedPayment(PaymentStatus::Succeeded, 150000, 'BDT', 'txn-late', 'CARD'),
            new VerifiedPayment(PaymentStatus::Failed, failureReason: 'Reversed.'),
            new VerifiedPayment(PaymentStatus::Canceled),
            new VerifiedPayment(PaymentStatus::Pending, failureReason: 'Held.'),
        ] as $answer) {
            $this->gateway->answer = $answer;

            expect(($this->confirm)($payment, ['val_id' => 'again'])->status)->toBe($final);
        }

        expect($this->paymentsOf($this->acme)->sole()->getAttributes())->toBe($stored)
            ->and($this->invoice->refresh()->status)->toBe($invoice)
            // It is not even asked about again.
            ->and($this->gateway->verifications)->toHaveCount(1)
            ->and($this->auditTrail($this->acme))->toHaveCount($audited)
            ->and(Mail::queued(PaymentReceivedMail::class))->toHaveCount($mailed);
    })->with([
        'succeeded' => [fn ($gateway) => null, PaymentStatus::Succeeded, InvoiceStatus::Paid],
        'failed' => [fn ($gateway) => $gateway->willFail(), PaymentStatus::Failed, InvoiceStatus::Open],
        'canceled' => [fn ($gateway) => $gateway->willCancel(), PaymentStatus::Canceled, InvoiceStatus::Open],
        'failed for the wrong amount' => [fn ($gateway) => $gateway->willConfirm(1, 'BDT'), PaymentStatus::Failed, InvoiceStatus::Open],
    ]);

    it('is settled once when the notification and the returning payer arrive together', function () {
        $payment = ($this->begin)();

        // Two requests, each holding the payment as it was before either was done.
        $notification = $this->paymentsOf($this->acme)->sole();
        $return = $this->paymentsOf($this->acme)->sole();

        $first = ($this->confirm)($notification);
        $second = ($this->confirm)($return);

        expect($first->status)->toBe(PaymentStatus::Succeeded)
            ->and($second->status)->toBe(PaymentStatus::Succeeded)
            ->and($second->paid_at->equalTo($first->paid_at))->toBeTrue()
            ->and($this->paymentsOf($this->acme))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.payment_received'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.subscription_activated'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied'))->toBeEmpty()
            ->and($this->subscriptionOf($this->acme)->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00');

        Mail::assertQueued(PaymentReceivedMail::class, 1);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
    });

    it('is left alone when it was recorded by an operator', function () {
        $offline = $this->payOpenInvoice($this->acme);
        $stored = $this->paymentsOf($this->acme)->sole()->getAttributes();

        $this->gateway->willFail();

        expect(($this->confirm)($offline)->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->paymentsOf($this->acme)->sole()->getAttributes())->toBe($stored)
            ->and($this->gateway->verifications)->toBe([]);
    });
});

describe('one gateway transaction', function () {
    it('pays one payment: a second one confirmed with the same gateway reference fails', function () {
        $first = ($this->begin)();
        $second = ($this->begin)();

        $this->gateway->willConfirm(150000, 'BDT', 'txn-same');

        $first = ($this->confirm)($first);
        $second = ($this->confirm)($second);

        expect($first->status)->toBe(PaymentStatus::Succeeded)
            ->and($first->gateway_reference)->toBe('txn-same')
            ->and($second->status)->toBe(PaymentStatus::Failed)
            ->and($second->failure_reason)->toBe('This gateway transaction was already recorded.')
            ->and($second->gateway_reference)->toBeNull()
            ->and($second->paid_at)->toBeNull();

        $stored = $this->paymentsOf($this->acme);

        expect($stored->pluck('status')->all())->toBe([PaymentStatus::Succeeded, PaymentStatus::Failed])
            ->and($stored->pluck('gateway_reference')->all())->toBe(['txn-same', null])
            ->and($this->auditTrail($this->acme, 'billing.payment_received'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed')->sole()->properties)
            ->toEqual(['status' => 'failed', 'reason' => 'This gateway transaction was already recorded.'])
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied'))->toBeEmpty();

        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('cannot pay for a second workspace either', function () {
        $globex = $this->createTenant('globex');

        $this->subscribe($globex, $this->starter, withTrial: false);

        $theirs = $this->inTenant($globex, function () use ($globex): Payment {
            app(PaymentProcessor::class)->begin($this->openInvoiceOf($globex), 'testpay', $this->ownerOf($globex));

            return Payment::query()->sole();
        });

        $this->gateway->willConfirm(150000, 'BDT', 'txn-shared');

        expect(($this->confirm)(($this->begin)())->status)->toBe(PaymentStatus::Succeeded);

        $theirs = $this->inTenant($globex, fn (): Payment => app(PaymentProcessor::class)->confirm($theirs));

        expect($theirs->status)->toBe(PaymentStatus::Failed)
            ->and($theirs->failure_reason)->toBe('This gateway transaction was already recorded.')
            ->and($this->openInvoiceOf($globex))->not->toBeNull()
            ->and($this->subscriptionOf($globex)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
    });

    it('may share its reference with a transaction of another gateway', function () {
        $viaGateway = ($this->begin)();

        $this->gateway->willConfirm(150000, 'BDT', 'REF-1');

        expect(($this->confirm)($viaGateway)->status)->toBe(PaymentStatus::Succeeded);

        // An operator's bank reference lives in the details, not in the column that is unique per gateway.
        $globex = $this->createTenant('globex');
        $this->subscribe($globex, $this->starter, withTrial: false);

        $offline = $this->inTenant($globex, fn (): Payment => app(PaymentProcessor::class)->recordOffline(
            $this->openInvoiceOf($globex),
            $this->createPlatformUser(),
            'bank_transfer',
            'REF-1',
        ));

        expect($offline->status)->toBe(PaymentStatus::Succeeded)
            ->and($offline->gateway_reference)->toBeNull();
    });
});

describe('money that arrives for an invoice that is no longer owed', function () {
    it('is kept and flagged when the invoice was withdrawn while the payer was at the gateway', function () {
        $payment = ($this->begin)();

        // The workspace changes its mind: the invoice is replaced by one for another plan.
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->changePlan($this->business, BillingInterval::Monthly));

        $replacement = $this->openInvoiceOf($this->acme);

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($replacement->id)->not->toBe($this->invoice->id);

        $payment = ($this->confirm)($payment);

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->amount)->toBe(150000)
            ->and($payment->invoice_id)->toBe($this->invoice->id)
            ->and($payment->paid_at)->not->toBeNull()
            // Nothing was bought with it ...
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($this->invoice->paid_at)->toBeNull()
            ->and($replacement->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter')
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toBeEmpty();

        // ... which is for an operator to put right.
        $unapplied = $this->auditTrail($this->acme, 'billing.payment_unapplied')->sole();

        expect($unapplied->subject_type)->toBe('payment')
            ->and($unapplied->subject_id)->toBe($payment->id)
            ->and($unapplied->properties)->toEqual(['invoice' => $this->invoice->number, 'invoice_status' => 'void'])
            ->and($this->auditTrail($this->acme, 'billing.payment_received'))->toHaveCount(1);

        // The payer still gets a receipt: the money was taken.
        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('is kept and flagged when the invoice was paid twice', function () {
        $first = ($this->begin)();
        $second = ($this->begin)();

        ($this->confirm)($first);

        $periodEnd = $this->subscriptionOf($this->acme)->current_period_end->toDateTimeString();

        $this->travel(1)->days();

        $second = ($this->confirm)($second);

        expect($second->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->paymentsOf($this->acme)->pluck('status')->all())->toBe([PaymentStatus::Succeeded, PaymentStatus::Succeeded])
            ->and($this->invoice->refresh()->paid_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
            // Paying twice does not buy two periods.
            ->and($this->subscriptionOf($this->acme)->current_period_end->toDateTimeString())->toBe($periodEnd)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied')->sole()->properties)
            ->toEqual(['invoice' => $this->invoice->number, 'invoice_status' => 'paid'])
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied')->sole()->subject_id)->toBe($second->id);
    });
});

describe('a payment recorded by an operator', function () {
    beforeEach(function () {
        $this->operator = $this->createPlatformUser();

        $this->record = fn (?Invoice $invoice = null, mixed ...$arguments): Payment => ($this->payments)(
            fn (PaymentProcessor $payments): Payment => $payments->recordOffline($invoice ?? $this->invoice, $this->operator, ...$arguments),
        );
    });

    it('is a succeeded payment in the operator\'s name that settles the invoice', function () {
        $payment = ($this->record)(
            null,
            'bank_transfer',
            'TRX-99120',
            'Paid at the Gulshan branch',
            Carbon::parse('2026-03-09 18:00:00'),
        );

        expect($payment->tenant_id)->toBe($this->acme->id)
            ->and($payment->invoice_id)->toBe($this->invoice->id)
            ->and($payment->gateway)->toBe(Payment::OFFLINE)
            ->and($payment->method)->toBe('bank_transfer')
            ->and($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->amount)->toBe(150000)
            ->and($payment->currency)->toBe('BDT')
            ->and($payment->reference)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($payment->gateway_reference)->toBeNull()
            ->and($payment->paid_at->toDateTimeString())->toBe('2026-03-09 18:00:00')
            ->and($payment->recorded_by)->toBe($this->operator->id)
            ->and($payment->refresh()->details)->toEqual(['bank_reference' => 'TRX-99120', 'note' => 'Paid at the Gulshan branch']);

        $invoice = $this->invoice->refresh();
        $subscription = $this->subscriptionOf($this->acme);

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            // Paid when the money came in, not when it was typed in.
            ->and($invoice->paid_at->toDateTimeString())->toBe('2026-03-09 18:00:00')
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00');

        // In the workspace's own trail, under the operator's name.
        $entry = $this->auditTrail($this->acme, 'billing.payment_recorded')->sole();

        expect($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($this->operator->id)
            ->and($entry->actor_label)->toBe($this->operator->email)
            ->and($entry->subject_type)->toBe('payment')
            ->and($entry->subject_id)->toBe($payment->id)
            ->and($entry->properties)->toEqual([
                'method' => 'bank_transfer',
                'invoice' => $invoice->number,
                'amount' => 150000,
                'currency' => 'BDT',
            ])
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied'))->toBeEmpty();

        Mail::assertQueued(PaymentReceivedMail::class, fn (PaymentReceivedMail $mail): bool => ($this->recipients)($mail) === ['ayesha@acme.example']
            && $mail->payment->is($payment));
        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('is dated now and carries no details unless they are given', function () {
        $payment = ($this->record)(null, 'cash');

        expect($payment->paid_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($payment->refresh()->details)->toBeNull()
            ->and($this->invoice->refresh()->paid_at->toDateTimeString())->toBe('2026-03-10 09:00:00');

        // Only the part that was given.
        $this->travelTo(Carbon::parse('2026-04-05 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect(($this->record)($this->openInvoiceOf($this->acme), 'cheque', null, 'Cheque no. 0042')->refresh()->details)
            ->toEqual(['note' => 'Cheque no. 0042']);
    });

    it('is refused for an invoice that is not waiting for payment', function (Closure $arrange) {
        $invoice = $arrange->call($this);
        $payments = $this->paymentsOf($this->acme)->count();
        $recorded = $this->auditTrail($this->acme, 'billing.payment_recorded')->count();

        expect(($this->refusal)(fn () => ($this->record)($invoice, 'cash')))
            ->toBe(['invoice' => 'This invoice is not waiting for payment.']);

        expect($this->paymentsOf($this->acme))->toHaveCount($payments)
            ->and($this->auditTrail($this->acme, 'billing.payment_recorded'))->toHaveCount($recorded);
    })->with([
        'paid already' => [function (): Invoice {
            $this->payOpenInvoice($this->acme);

            return $this->invoice;
        }],
        'paid already, through a gateway' => [function (): Invoice {
            ($this->confirm)(($this->begin)());

            return $this->invoice;
        }],
        'withdrawn' => [function (): Invoice {
            $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($this->openInvoiceOf($this->acme)));

            return $this->invoice;
        }],
        'for nothing' => [function (): Invoice {
            $this->invoice->update(['credit_amount' => 150000, 'total_amount' => 0]);

            return $this->invoice;
        }],
    ]);

    it('judges the invoice as it is now, not the copy it was handed', function () {
        // The copy says "open"; meanwhile another operator recorded the same transfer.
        $copy = $this->openInvoiceOf($this->acme);

        ($this->record)(null, 'bank_transfer');

        expect(($this->refusal)(fn () => ($this->record)($copy, 'bank_transfer')))->toHaveKey('invoice')
            ->and($this->paymentsOf($this->acme))->toHaveCount(1);

        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('cannot reach an invoice of another workspace', function () {
        $globex = $this->createTenant('globex');
        $this->subscribe($globex, $this->starter, withTrial: false);

        $theirs = $this->openInvoiceOf($globex);

        expect(fn () => ($this->record)($theirs, 'cash'))->toThrow(ModelNotFoundException::class);

        expect($theirs->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and(Payment::withoutTenancy()->count())->toBe(0);
    });
});

describe('an offer to change plan', function () {
    beforeEach(function () {
        $this->payOpenInvoice($this->acme);
        $this->travel(5)->days();

        // Starter to Business inside the paid month: an invoice with credit, good for three days.
        $this->offer = $this->inTenant(
            $this->acme,
            fn (): Invoice => app(SubscriptionManager::class)->changePlan($this->business, BillingInterval::Monthly)->invoice,
        );

        $this->operator = $this->createPlatformUser();
        $this->expired = 'This offer to change plan has expired. Choose the plan again to get a current invoice.';
    });

    it('can be paid while it stands', function () {
        expect($this->offer->kind)->toBe(InvoiceKind::Change)
            ->and($this->offer->due_at->toDateTimeString())->toBe('2026-03-18 09:00:00');

        $this->travelTo(Carbon::parse('2026-03-18 08:59:00'));

        $payment = ($this->confirm)(($this->begin)($this->offer));

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->amount)->toBe($this->offer->total_amount)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('business');
    });

    it('cannot be paid once it has expired, online or offline, even before the cycle withdraws it', function () {
        $this->travelTo(Carbon::parse('2026-03-18 09:00:01'));

        expect($this->offer->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and(($this->refusal)(fn () => ($this->begin)($this->offer)))->toBe(['invoice' => $this->expired])
            ->and(($this->refusal)(fn () => ($this->payments)(
                fn (PaymentProcessor $payments) => $payments->recordOffline($this->offer, $this->operator, 'cash'),
            )))->toBe(['invoice' => $this->expired]);

        expect($this->paymentsOf($this->acme))->toHaveCount(1)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter');
    });

    it('is still honoured for a payer who was already at the gateway when it expired', function () {
        $payment = ($this->begin)($this->offer);

        $this->travelTo(Carbon::parse('2026-03-18 09:10:00'));

        $payment = ($this->confirm)($payment);
        $subscription = $this->subscriptionOf($this->acme);

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->offer->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-18 09:10:00')
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied'))->toBeEmpty();
    });

    it('is flagged, not applied, when the money arrives after the cycle withdrew it', function () {
        $payment = ($this->begin)($this->offer);

        $this->travelTo(Carbon::parse('2026-03-18 10:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->offer->refresh()->status)->toBe(InvoiceStatus::Void);

        $payment = ($this->confirm)($payment);

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter')
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied')->sole()->properties)
            ->toEqual(['invoice' => $this->offer->number, 'invoice_status' => 'void']);
    });
});

describe('billing mail', function () {
    it('is tenant-bound, queued work', function (string $mailable) {
        expect(is_subclass_of($mailable, RequiresTenantContext::class))->toBeTrue()
            ->and(is_subclass_of($mailable, ShouldQueue::class))->toBeTrue();
    })->with([InvoiceIssuedMail::class, PaymentReceivedMail::class]);

    it('goes to the owner, on the billing queue', function () {
        // Other people in the workspace, also one who may manage billing, are not written to.
        $this->addMember($this->acme, Role::ADMIN);

        Mail::assertQueued(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail): bool => ($this->recipients)($mail) === ['ayesha@acme.example']
            && $mail->queue === 'billing'
            && $mail->locale === 'en'
            && $mail->invoice->is($this->invoice));
        Mail::assertQueued(InvoiceIssuedMail::class, 1);

        $payment = $this->payOpenInvoice($this->acme);

        Mail::assertQueued(PaymentReceivedMail::class, fn (PaymentReceivedMail $mail): bool => ($this->recipients)($mail) === ['ayesha@acme.example']
            && $mail->queue === 'billing'
            && $mail->locale === 'en'
            && $mail->payment->is($payment));
        Mail::assertQueued(PaymentReceivedMail::class, 1);
        Mail::assertNothingSent();
    });

    it('is written in the language of the owner, or of the workspace when the owner has not chosen one', function (?string $owner, string $workspace, string $expected) {
        $person = User::factory()->create(['email' => 'malik@dhaka.example', 'locale' => $owner]);
        $dhaka = $this->createTenant('dhaka', ['name' => 'Dhaka Traders', 'locale' => $workspace], $person);

        $this->subscribe($dhaka, $this->starter, withTrial: false);
        $this->payOpenInvoice($dhaka);

        Mail::assertQueued(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail): bool => ($this->recipients)($mail) === ['malik@dhaka.example']
            && $mail->locale === $expected);
        Mail::assertQueued(PaymentReceivedMail::class, fn (PaymentReceivedMail $mail): bool => ($this->recipients)($mail) === ['malik@dhaka.example']
            && $mail->locale === $expected);

        // Whatever language the mail is in, the request goes on in its own.
        expect(app()->getLocale())->toBe('en');
    })->with([
        'a Bengali owner of an English workspace' => ['bn', 'en', 'bn'],
        'an English owner of a Bengali workspace' => ['en', 'bn', 'en'],
        'no preference, Bengali workspace' => [null, 'bn', 'bn'],
        'no preference, English workspace' => [null, 'en', 'en'],
    ]);

    describe('rendered', function () {
        beforeEach(function () {
            // Evening in UTC is already the next day in Dhaka, the workspace's time zone.
            $this->travelTo(Carbon::parse('2026-03-10 20:00:00'));

            $this->dhaka = $this->createTenant('dhaka', ['name' => 'Dhaka Traders']);
            $this->subscribe($this->dhaka, $this->business, withTrial: false);

            $this->invoice = $this->openInvoiceOf($this->dhaka);
            $this->link = "http://dhaka.localhost/billing/invoices/{$this->invoice->id}";

            /** ICU separates a currency code from the amount with a no-break space. */
            $this->html = fn ($mailable): string => str_replace("\u{00A0}", ' ', $this->inTenant($this->dhaka, fn (): string => $mailable->render()));
            $this->subject = fn ($mailable): string => $this->inTenant($this->dhaka, fn (): string => $mailable->envelope()->subject);
        });

        it('asks for the invoice with a link into the workspace', function () {
            $mail = new InvoiceIssuedMail($this->invoice);
            $html = ($this->html)($mail);

            expect(($this->subject)($mail))->toBe("Invoice {$this->invoice->number} for Dhaka Traders")
                ->and($html)->toContain(
                    $this->invoice->number,
                    'Dhaka Traders',
                    'Business',
                    'BDT 4,500.00',
                    // The period and the due date as days on the workspace's clock.
                    'March 11, 2026 to April 11, 2026',
                    'It is due on March 11, 2026.',
                    'View invoice',
                    $this->link,
                )
                ->and($html)->not->toContain('March 10, 2026', 'April 10, 2026');
        });

        it('thanks for the payment with the reference and the same link', function () {
            $this->travelTo(Carbon::parse('2026-03-12 19:30:00'));

            $payment = $this->payOpenInvoice($this->dhaka);
            $mail = new PaymentReceivedMail($payment);
            $html = ($this->html)($mail);

            expect(($this->subject)($mail))->toBe("Payment received for invoice {$this->invoice->number}")
                ->and($html)->toContain(
                    'Payment received',
                    'BDT 4,500.00',
                    'Dhaka Traders',
                    $this->invoice->number,
                    'March 13, 2026',
                    $payment->reference,
                    $this->link,
                )
                ->and($html)->not->toContain('March 12, 2026');
        });

        it('writes amounts and dates the Bengali way for a Bengali reader', function () {
            $html = ($this->html)((new InvoiceIssuedMail($this->invoice))->locale('bn'));

            expect($html)->toContain('৪,৫০০.০০', '৳', 'মার্চ', $this->link)
                ->and($html)->not->toContain('BDT 4,500.00', 'March 11, 2026')
                // Writing a mail in another language does not change the language of the request.
                ->and(app()->getLocale())->toBe('en');
        });

        it('cannot be written outside a workspace', function () {
            $payment = $this->payOpenInvoice($this->dhaka);

            expect(fn () => (new InvoiceIssuedMail($this->invoice))->render())->toThrow(MissingTenantContextException::class)
                ->and(fn () => (new PaymentReceivedMail($payment))->render())->toThrow(MissingTenantContextException::class);
        });
    });

    describe('delivered', function () {
        beforeEach(function () {
            Mail::swap($this->mailer);

            $this->sent = fn () => collect(app('mailer')->getSymfonyTransport()->messages())
                ->map(fn ($message) => $message->getOriginalMessage());
        });

        it('is sent as soon as the payment is committed, on the sync queue', function () {
            $payment = $this->payOpenInvoice($this->acme);

            $receipt = ($this->sent)()->sole();

            expect($receipt->getTo()[0]->getAddress())->toBe('ayesha@acme.example')
                ->and($receipt->getSubject())->toBe("Payment received for invoice {$this->invoice->number}")
                ->and($receipt->getHtmlBody())->toContain($payment->reference, "http://acme.localhost/billing/invoices/{$this->invoice->id}");
        });

        it('is not sent for a payment that was rolled back', function () {
            try {
                $this->inTenant($this->acme, function (): void {
                    DB::transaction(function (): void {
                        app(PaymentProcessor::class)->recordOffline($this->invoice, $this->createPlatformUser(), 'cash');

                        throw new RuntimeException('Something else in the request failed.');
                    });
                });
            } catch (RuntimeException) {
                // expected
            }

            expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
                ->and($this->paymentsOf($this->acme))->toBeEmpty()
                ->and(($this->sent)())->toHaveCount(0);
        });

        it('survives a real queue: its own queue, the tenant restored, the same link', function () {
            config(['queue.default' => 'database']);

            $this->payOpenInvoice($this->acme);

            // The renewal, issued by the cycle a week before the month is over: one mail asking for it.
            $this->travelTo(Carbon::parse('2026-04-05 09:00:00'));
            $this->runBillingCycle($this->acme);

            $renewal = $this->openInvoiceOf($this->acme);
            $jobs = DB::table('jobs')->orderBy('id')->get();
            $payloads = $jobs->map(fn ($job): array => json_decode($job->payload, true));

            expect($jobs->pluck('queue')->all())->toBe(['billing', 'billing'])
                ->and($payloads->pluck('displayName')->all())->toBe([PaymentReceivedMail::class, InvoiceIssuedMail::class])
                ->and($payloads->pluck('tenant_id')->all())->toBe([$this->acme->id, $this->acme->id])
                ->and(($this->sent)())->toHaveCount(0);

            // A worker with no tenant active picks them up.
            $this->inCentral(function (): void {
                foreach ([1, 2] as $ignored) {
                    $this->artisan('queue:work', ['--once' => true, '--queue' => 'billing', '--tries' => 1, '--sleep' => 0]);
                }
            });

            expect(DB::table('failed_jobs')->count())->toBe(0)
                ->and(DB::table('jobs')->count())->toBe(0);

            [$receipt, $request] = ($this->sent)()->all();

            expect($receipt->getSubject())->toBe("Payment received for invoice {$this->invoice->number}")
                ->and($receipt->getHtmlBody())->toContain("http://acme.localhost/billing/invoices/{$this->invoice->id}")
                ->and($request->getTo()[0]->getAddress())->toBe('ayesha@acme.example')
                ->and($request->getSubject())->toBe("Invoice {$renewal->number} for Acme")
                ->and($request->getHtmlBody())->toContain("http://acme.localhost/billing/invoices/{$renewal->id}", 'Starter');
        });

        it('is queued for its workspace when the change is committed outside the workspace, as sign-up does', function () {
            config(['queue.default' => 'database']);

            $dhaka = $this->createTenant('dhaka', ['name' => 'Dhaka Traders']);
            $context = app(TenantContext::class);

            $this->inCentral(function () use ($dhaka, $context): void {
                DB::transaction(function () use ($dhaka, $context): void {
                    $context->run($dhaka, function (): void {
                        app(SubscriptionManager::class)->start($this->starter, BillingInterval::Monthly, withTrial: false);
                    });

                    // Nothing leaves before the commit.
                    expect(DB::table('jobs')->count())->toBe(0);
                });

                expect($context->check())->toBeFalse();
            });

            $payload = json_decode(DB::table('jobs')->sole()->payload, true);

            expect($payload['displayName'])->toBe(InvoiceIssuedMail::class)
                ->and($payload['tenant_id'])->toBe($dhaka->id);

            $this->inCentral(fn () => $this->artisan('queue:work', ['--once' => true, '--queue' => 'billing', '--tries' => 1, '--sleep' => 0]));

            expect(DB::table('failed_jobs')->count())->toBe(0)
                ->and(($this->sent)()->sole()->getSubject())->toContain('Dhaka Traders');
        });

        it('is not queued at all when the change is rolled back', function () {
            config(['queue.default' => 'database']);

            $dhaka = $this->createTenant('dhaka', ['name' => 'Dhaka Traders']);
            $context = app(TenantContext::class);

            try {
                DB::transaction(function () use ($dhaka, $context): void {
                    $context->run($dhaka, function (): void {
                        app(SubscriptionManager::class)->start($this->starter, BillingInterval::Monthly, withTrial: false);
                    });

                    throw new RuntimeException('Something later in the same change failed.');
                });
            } catch (RuntimeException) {
                // Expected.
            }

            expect(DB::table('jobs')->count())->toBe(0)
                ->and($this->invoicesOf($dhaka))->toBeEmpty()
                ->and(($this->sent)())->toHaveCount(0);
        });

        it('fails, instead of writing to the wrong workspace, when its tenant is gone from the payload', function () {
            config(['queue.default' => 'database']);

            $this->payOpenInvoice($this->acme);

            $job = DB::table('jobs')->sole();
            $payload = json_decode($job->payload, true);
            unset($payload['tenant_id']);
            DB::table('jobs')->where('id', $job->id)->update(['payload' => json_encode($payload)]);

            $globex = $this->createTenant('globex');

            // Even with another tenant still active in the worker.
            $this->inTenant($globex, fn () => $this->artisan('queue:work', ['--once' => true, '--queue' => 'billing', '--tries' => 1, '--sleep' => 0]));

            expect(($this->sent)())->toHaveCount(0)
                ->and(DB::table('failed_jobs')->sole()->exception)->toContain(MissingTenantContextException::class);
        });
    });
});
