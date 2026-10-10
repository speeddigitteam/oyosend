<?php

declare(strict_types=1);

use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Gateways\VerifiedPayment;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Mail\PaymentReceivedMail;
use App\Core\Billing\Models\Payment;
use App\Core\Tenancy\Enums\TenantStatus;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/*
 * Paying an invoice online, from the button to the settled invoice:
 *
 *   pay -> a pending payment and the address of the gateway
 *   the gateway sends the payer back (return) and notifies the server (notify)
 *   both ask the gateway what happened, and only its answer settles anything
 *
 * The gateway is tests/Support/Fixtures/TestGateway: it never leaves the
 * process and answers what the test tells it to.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->gateway = $this->useTestGateway();

    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000, 'yearly_amount' => 1500000]);

    // A plan taken without a trial: invoiced at once, payment overdue from the start.
    $this->subscribe($this->acme, $this->starter, withTrial: false);
    $this->invoice = $this->openInvoiceOf($this->acme);

    $this->payUrl = "http://acme.localhost/billing/invoices/{$this->invoice->id}/pay";

    $this->pay = fn (string $gateway = 'testpay', array $headers = []) => $this->actingAsOwner($this->acme)
        ->post($this->payUrl, ['gateway' => $gateway], $headers);

    // Start a payment as the owner and hand back its record.
    $this->begin = function (): Payment {
        ($this->pay)();

        return $this->paymentsOf($this->acme)->last();
    };

    // What the gateway does next, from a browser that carries nothing of ours.
    $this->returnUrl = fn (Payment|string $payment): string => 'http://acme.localhost/billing/payments/'
        .($payment instanceof Payment ? $payment->reference : $payment).'/return';

    $this->comeBack = fn (Payment $payment, string $method = 'POST', array $payload = []) => $this->startNewBrowserSession()
        ->call($method, ($this->returnUrl)($payment), $payload);

    $this->notify = fn (array $payload, string $gateway = 'testpay') => $this->startNewBrowserSession()
        ->post("http://acme.localhost/billing/gateways/{$gateway}/notify", $payload);

    $this->fresh = fn (Payment $payment): Payment => Payment::forTenant($this->acme)->findOrFail($payment->id);
    $this->invoiceNow = fn () => $this->invoicesOf($this->acme)->firstWhere('id', $this->invoice->id);
});

describe('starting a payment', function () {
    it('opens a pending payment and hands an Inertia visit the address of the gateway', function () {
        $response = ($this->pay)('testpay', ['X-Inertia' => 'true']);

        $payment = $this->paymentsOf($this->acme)->sole();

        // Another origin: Inertia is told to leave the page for it.
        $response->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://pay.test/checkout/'.$payment->reference);

        expect($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->invoice_id)->toBe($this->invoice->id)
            ->and($payment->gateway)->toBe('testpay')
            ->and($payment->amount)->toBe(150000)
            ->and($payment->currency)->toBe('BDT')
            ->and($payment->reference)->toMatch('/^[0-9A-Z]{26}$/')
            ->and($payment->gateway_reference)->toBeNull()
            ->and($payment->paid_at)->toBeNull()
            ->and($payment->recorded_by)->toBeNull()
            ->and($payment->details)->toEqual(['session' => 'sess-'.$payment->reference])
            // Asking to pay settles nothing.
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);

        $entry = $this->auditTrail($this->acme, 'billing.payment_started')->sole();

        expect($entry->actor_id)->toBe($this->ownerOf($this->acme)->id)
            ->and($entry->subject_id)->toBe($payment->id)
            ->and($entry->properties)->toEqual(['gateway' => 'testpay', 'invoice' => $this->invoice->number]);
    });

    it('redirects a plain form post to the gateway', function () {
        $response = ($this->pay)();

        $response->assertRedirect('https://pay.test/checkout/'.$this->paymentsOf($this->acme)->sole()->reference);
    });

    it('tells the gateway who pays, for what, and where to send them back', function () {
        $this->acme->update(['legal_name' => 'Acme Trading Limited', 'phone' => '+8801700000000', 'city' => 'Dhaka', 'country' => 'BD']);

        $payment = ($this->begin)();
        $request = $this->gateway->requests[0];

        expect($this->gateway->requests)->toHaveCount(1)
            ->and($request->description)->toBe('Invoice '.$this->invoice->number)
            ->and($request->customerName)->toBe('Acme Trading Limited')
            // The workspace has no billing address of its own: the person paying.
            ->and($request->customerEmail)->toBe($this->ownerOf($this->acme)->email)
            ->and($request->customerPhone)->toBe('+8801700000000')
            ->and($request->city)->toBe('Dhaka')
            ->and($request->country)->toBe('BD')
            ->and($request->address)->toBeNull()
            // Both on the workspace's own host, so the tenant comes from the hostname there too.
            ->and($request->returnUrl)->toBe("http://acme.localhost/billing/payments/{$payment->reference}/return")
            ->and($request->notifyUrl)->toBe('http://acme.localhost/billing/gateways/testpay/notify');
    });

    it('offers a gateway on the pages only while it can take this payment', function () {
        $gateways = fn (string $path): array => $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/'.$path)
            ->assertOk()
            ->inertiaProps('gateways');

        $invoicePage = "billing/invoices/{$this->invoice->id}";
        $offered = [['key' => 'testpay', 'name' => 'TestPay']];

        expect($gateways($invoicePage))->toBe($offered)
            ->and($gateways('billing'))->toBe($offered);

        // No credentials: not offered, and the page says that no online method is set up.
        $this->gateway->configured = false;

        expect($gateways($invoicePage))->toBe([])
            ->and($gateways('billing'))->toBe([]);

        // Configured, but not for the currency of this invoice.
        $this->gateway->configured = true;
        $this->gateway->currencies = ['USD'];

        expect($gateways($invoicePage))->toBe([])
            ->and($gateways('billing'))->toBe([]);

        // Nothing is offered for an invoice that is no longer waiting for payment.
        $this->gateway->currencies = ['BDT'];
        $this->payOpenInvoice($this->acme);

        expect($gateways($invoicePage))->toBe([])
            ->and($gateways('billing'))->toBe([]);
    });

    it('refuses a gateway that does not exist or cannot take the payment', function (Closure $arrange, string $key) {
        $arrange($this->gateway);

        ($this->pay)($key)->assertSessionHasErrors(['gateway' => 'This payment method is not available.']);

        expect($this->paymentsOf($this->acme))->toBeEmpty()
            ->and($this->gateway->requests)->toBe([]);
    })->with([
        'an unknown key' => [fn ($gateway) => null, 'nopay'],
        'money recorded by an operator is not a gateway' => [fn ($gateway) => null, 'offline'],
        'one without credentials' => [fn ($gateway) => $gateway->configured = false, 'testpay'],
        'one that does not take the currency' => [fn ($gateway) => $gateway->currencies = ['USD'], 'testpay'],
        'the built-in one, which has no credentials here' => [fn ($gateway) => null, 'sslcommerz'],
    ]);

    it('validates the gateway it is sent', function (array $data) {
        $this->actingAsOwner($this->acme)->post($this->payUrl, $data)->assertSessionHasErrors('gateway');

        expect($this->paymentsOf($this->acme))->toBeEmpty();
    })->with([
        'nothing' => [[]],
        'a list' => [['gateway' => ['testpay']]],
        'too long' => [['gateway' => str_repeat('a', 41)]],
    ]);

    it('marks the payment failed when the gateway cannot open it', function () {
        $this->gateway->initiationFailure = new GatewayException('The store is not active.');

        ($this->pay)()->assertSessionHasErrors([
            'gateway' => 'The payment could not be started. Please try again, or choose another payment method.',
        ]);

        $payment = $this->paymentsOf($this->acme)->sole();

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_reason)->toBe('The store is not active.')
            ->and($payment->failed_at)->not->toBeNull()
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed')->sole()->properties)
            ->toEqual(['status' => 'failed', 'reason' => 'The store is not active.'])
            ->and($this->auditTrail($this->acme, 'billing.payment_started'))->toBeEmpty();
    });

    it('says what is missing when the gateway needs the company\'s billing details', function () {
        $this->gateway->initiationFailure = GatewayException::missingBillingDetails('testpay');

        ($this->pay)()->assertSessionHasErrors([
            'gateway' => 'Add the company phone number, address, city, postal code and country in the workspace settings before paying online.',
        ]);

        expect($this->paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Failed);
    });

    it('lets the payer try again after a failed start', function () {
        $this->gateway->initiationFailure = new GatewayException('Temporarily unavailable.');
        ($this->pay)();

        $this->gateway->initiationFailure = null;
        ($this->pay)()->assertRedirect()->assertSessionHasNoErrors();

        expect($this->paymentsOf($this->acme)->map(fn (Payment $payment): string => $payment->status->value)->all())
            ->toBe(['failed', 'pending']);
    });
});

describe('an invoice that cannot be paid', function () {
    it('refuses one that is already paid', function () {
        $this->payOpenInvoice($this->acme);

        ($this->pay)()->assertSessionHasErrors(['invoice' => 'This invoice is not waiting for payment.']);

        // Only the payment that settled it.
        expect($this->paymentsOf($this->acme))->toHaveCount(1)
            ->and($this->gateway->requests)->toBe([]);
    });

    it('refuses one that was withdrawn', function () {
        $this->inTenant($this->acme, function (): void {
            app(InvoiceIssuer::class)->voidOpen();
        });

        ($this->pay)()->assertSessionHasErrors(['invoice' => 'This invoice is not waiting for payment.']);

        expect($this->paymentsOf($this->acme))->toBeEmpty();
    });

    it('refuses an offer to change plan that has run out', function () {
        $business = $this->createPlan(['key' => 'business', 'name' => 'Business', 'monthly_amount' => 450000]);

        $this->payOpenInvoice($this->acme);
        $this->travel(5)->days();

        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/billing/subscription', ['plan_id' => $business->id, 'interval' => 'monthly']);

        $offer = $this->openInvoiceOf($this->acme);
        $payOffer = fn () => $this->actingAsOwner($this->acme)
            ->post("http://acme.localhost/billing/invoices/{$offer->id}/pay", ['gateway' => 'testpay']);

        // Its credit was worked out for the day it was issued: three days to pay.
        $this->travel(2)->days();
        $payOffer()->assertSessionHasNoErrors();

        $this->travel(2)->days();
        $payOffer()->assertSessionHasErrors([
            'invoice' => 'This offer to change plan has expired. Choose the plan again to get a current invoice.',
        ]);

        expect($this->paymentsOf($this->acme)->where('invoice_id', $offer->id))->toHaveCount(1)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter');
    });
});

describe('the payer coming back from the gateway', function () {
    it('needs no session and no CSRF token, and starts no session of its own', function (string $method) {
        $payment = ($this->begin)();

        $response = ($this->comeBack)($payment, $method, ['status' => 'VALID', 'val_id' => 'v-123']);

        // To the billing page, which is signed in and reports what happened.
        $response->assertStatus(303)
            ->assertRedirect("http://acme.localhost/billing?payment={$payment->reference}");

        expect($response->headers->getCookies())->toBe([])
            ->and($response->headers->has('Set-Cookie'))->toBeFalse()
            ->and($this->isAuthenticated('web'))->toBeFalse();
    })->with(['POST', 'GET']);

    it('runs outside the web middleware group', function (string $name) {
        // Middleware groups reach the router when the HTTP kernel is built.
        app(HttpKernel::class);

        $route = Route::getRoutes()->getByName($name);
        $pipeline = implode(' ', app('router')->gatherRouteMiddleware($route));

        expect($route->gatherMiddleware())->toBe(['tenant.callback'])
            ->and($pipeline)->toContain('InitializeTenancy', 'ThrottleRequests')
            ->not->toMatch('/Session|Cookie|Csrf|RequestForgery|Inertia|Authenticate/i');
    })->with(['core.billing.payments.return', 'core.billing.gateways.notify']);

    it('asks the gateway, settles the invoice and starts the paid period', function () {
        $payment = ($this->begin)();

        $this->travel(10)->minutes();
        ($this->comeBack)($payment, 'POST', ['status' => 'VALID', 'val_id' => 'v-123']);

        // What arrived is only passed on to the gateway to look the payment up.
        expect($this->gateway->verifications)->toBe([['status' => 'VALID', 'val_id' => 'v-123']]);

        $payment = ($this->fresh)($payment);
        $invoice = ($this->invoiceNow)();
        $subscription = $this->subscriptionOf($this->acme);

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->gateway_reference)->toBe('txn-'.$payment->reference)
            ->and($payment->method)->toBe('CARD')
            ->and($payment->paid_at->toDateTimeString())->toBe('2026-03-10 09:10:00')
            ->and($payment->failure_reason)->toBeNull()
            ->and($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->paid_at->toDateTimeString())->toBe('2026-03-10 09:10:00')
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            // The period the invoice was issued for.
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->grace_ends_at)->toBeNull();

        $received = $this->auditTrail($this->acme, 'billing.payment_received')->sole();

        // Nobody is signed in on this request: the entry has no actor.
        expect($received->actor_id)->toBeNull()
            ->and($received->properties)->toEqual(['gateway' => 'testpay', 'amount' => 150000, 'currency' => 'BDT'])
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.subscription_activated'))->toHaveCount(1);

        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('does not believe what the browser brings back', function () {
        $payment = ($this->begin)();
        $this->gateway->willFail('Insufficient funds.');

        // The payload claims success; the gateway says otherwise.
        ($this->comeBack)($payment, 'POST', ['status' => 'VALID', 'amount' => '1500.00', 'paid' => '1'])
            ->assertRedirect("http://acme.localhost/billing?payment={$payment->reference}");

        expect(($this->fresh)($payment)->status)->toBe(PaymentStatus::Failed)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open);
    });

    it('tells the signed-in payer what became of the payment', function () {
        $payment = ($this->begin)();

        $returned = fn (string $reference): mixed => $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/billing?payment='.$reference)
            ->assertOk()
            ->inertiaProps('returnedPayment');

        expect($returned($payment->reference))->toMatchArray([
            'id' => $payment->id,
            'reference' => $payment->reference,
            'gateway' => 'testpay',
            'status' => 'pending',
            'amount' => 150000,
            'currency' => 'BDT',
            'paidAt' => null,
            'failureReason' => null,
        ]);

        ($this->comeBack)($payment);

        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/billing?payment='.$payment->reference)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('returnedPayment.status', 'succeeded')
                ->where('returnedPayment.method', 'CARD')
                ->where('returnedPayment.paidAt', '2026-03-10T09:00:00+00:00')
                ->where('subscription.status', 'active')
                ->where('openInvoice', null));

        // A reference that is not one of this workspace's payments shows nothing.
        expect($returned(str_repeat('0', 26)))->toBeNull()
            ->and($returned('not-a-reference'))->toBeNull()
            ->and($this->actingAsOwner($this->acme)->get('http://acme.localhost/billing?payment[]=x')->assertOk()->inertiaProps('returnedPayment'))
            ->toBeNull();
    });

    it('redirects quietly for a reference it does not know', function () {
        $unknown = str_repeat('0', 26);

        $this->startNewBrowserSession()
            ->post(($this->returnUrl)($unknown))
            ->assertStatus(303)
            ->assertRedirect("http://acme.localhost/billing?payment={$unknown}");

        expect($this->gateway->verifications)->toBe([]);

        // Not the shape of a reference: not a route at all.
        $this->post(($this->returnUrl)('abc'))->assertNotFound();
    });

    it('cannot be used from another workspace\'s address', function () {
        $globex = $this->createTenant('globex');
        $payment = ($this->begin)();

        $this->startNewBrowserSession()
            ->post("http://globex.localhost/billing/payments/{$payment->reference}/return")
            ->assertRedirect("http://globex.localhost/billing?payment={$payment->reference}");

        $this->startNewBrowserSession()
            ->post('http://globex.localhost/billing/gateways/testpay/notify', ['reference' => $payment->reference])
            ->assertOk();

        expect($this->gateway->verifications)->toBe([])
            ->and(($this->fresh)($payment)->status)->toBe(PaymentStatus::Pending)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open);

        // And the payer's own billing page in that workspace shows nothing of it.
        $this->actingAsOwner($globex)
            ->get('http://globex.localhost/billing?payment='.$payment->reference)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returnedPayment', null));
    });

    it('still records the money for a workspace that has been suspended meanwhile', function () {
        $payment = ($this->begin)();

        $this->acme->update(['status' => TenantStatus::Suspended]);

        ($this->comeBack)($payment)->assertStatus(303);

        expect(($this->fresh)($payment)->status)->toBe(PaymentStatus::Succeeded)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Paid);
    });
});

describe('the gateway notifying the server', function () {
    it('confirms the payment and always answers OK', function () {
        $payment = ($this->begin)();

        $response = ($this->notify)(['reference' => $payment->reference, 'status' => 'VALID']);

        $response->assertOk()->assertContent('OK');

        expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
            ->and($response->headers->getCookies())->toBe([])
            ->and($this->gateway->verifications)->toBe([['reference' => $payment->reference, 'status' => 'VALID']])
            ->and(($this->fresh)($payment)->status)->toBe(PaymentStatus::Succeeded)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Paid)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
    });

    it('gives the same answer whatever it is sent, and does nothing', function (Closure $payload, string $gateway) {
        $payment = ($this->begin)();

        ($this->notify)($payload($payment), $gateway)->assertOk()->assertContent('OK');

        expect($this->gateway->verifications)->toBe([])
            ->and(($this->fresh)($payment)->status)->toBe(PaymentStatus::Pending)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open);
    })->with([
        'an unknown reference' => [fn (Payment $payment) => ['reference' => str_repeat('0', 26)], 'testpay'],
        'no reference' => [fn (Payment $payment) => [], 'testpay'],
        'a reference that is not text' => [fn (Payment $payment) => ['reference' => ['a']], 'testpay'],
        'an unknown gateway' => [fn (Payment $payment) => ['reference' => $payment->reference], 'nopay'],
        'a payment made through another gateway' => [fn (Payment $payment) => ['tran_id' => $payment->reference], 'sslcommerz'],
    ]);
});

describe('being told more than once', function () {
    it('settles once, however often the payer returns and the gateway notifies', function () {
        $payment = ($this->begin)();

        ($this->comeBack)($payment);
        ($this->notify)(['reference' => $payment->reference])->assertOk();
        ($this->comeBack)($payment, 'GET')->assertStatus(303);
        ($this->notify)(['reference' => $payment->reference])->assertOk();

        $payments = $this->paymentsOf($this->acme);

        // The gateway was asked once; after that the payment is final.
        expect($this->gateway->verifications)->toHaveCount(1)
            ->and($payments)->toHaveCount(1)
            ->and($payments->sole()->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->invoicesOf($this->acme)->sole()->status)->toBe(InvoiceStatus::Paid)
            ->and($this->auditTrail($this->acme, 'billing.payment_received'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.subscription_activated'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.payment_unapplied'))->toBeEmpty()
            ->and($this->subscriptionOf($this->acme)->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00');

        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('does not turn a failed payment into a paid one later', function () {
        $payment = ($this->begin)();

        $this->gateway->willFail();
        ($this->comeBack)($payment);

        // A late, forged or repeated notification changes nothing: the payment is final.
        $this->gateway->answer = null;
        ($this->notify)(['reference' => $payment->reference]);
        ($this->comeBack)($payment);

        expect(($this->fresh)($payment)->status)->toBe(PaymentStatus::Failed)
            ->and($this->gateway->verifications)->toHaveCount(1)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open);
    });

    it('records one gateway transaction against one payment only', function () {
        $first = ($this->begin)();
        $second = ($this->begin)();

        $this->gateway->willConfirm(150000, 'BDT', 'txn-same');

        ($this->comeBack)($first);
        ($this->comeBack)($second);

        expect(($this->fresh)($first)->status)->toBe(PaymentStatus::Succeeded)
            ->and(($this->fresh)($second)->status)->toBe(PaymentStatus::Failed)
            ->and(($this->fresh)($second)->failure_reason)->toBe('This gateway transaction was already recorded.')
            ->and(($this->fresh)($second)->gateway_reference)->toBeNull()
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(1);
    });
});

describe('a payment that did not succeed', function () {
    it('leaves the invoice open and records why', function (Closure $arrange, string $status, ?string $reason) {
        $payment = ($this->begin)();
        $arrange($this->gateway);

        ($this->comeBack)($payment)
            ->assertStatus(303)
            ->assertRedirect("http://acme.localhost/billing?payment={$payment->reference}");

        $payment = ($this->fresh)($payment);

        expect($payment->status->value)->toBe($status)
            ->and($payment->failure_reason)->toBe($reason)
            ->and($payment->failed_at)->not->toBeNull()
            ->and($payment->paid_at)->toBeNull()
            ->and($payment->gateway_reference)->toBeNull()
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed')->sole()->properties)
            ->toEqual(['status' => $status, 'reason' => $reason])
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toBeEmpty();

        Mail::assertNotQueued(PaymentReceivedMail::class);

        // The billing page says so, with the reason.
        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/billing?payment='.$payment->reference)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('returnedPayment.status', $status)
                ->where('returnedPayment.failureReason', $reason)
                ->where('openInvoice.id', $this->invoice->id));
    })->with([
        'declined' => [fn ($gateway) => $gateway->willFail('Declined by the bank.'), 'failed', 'Declined by the bank.'],
        'given up by the payer' => [fn ($gateway) => $gateway->willCancel(), 'canceled', null],
        'confirmed for another amount' => [
            fn ($gateway) => $gateway->willConfirm(149999, 'BDT'),
            'failed',
            'The gateway confirmed a different amount or currency than the invoice.',
        ],
        'confirmed for more than was asked' => [
            fn ($gateway) => $gateway->willConfirm(1500000, 'BDT'),
            'failed',
            'The gateway confirmed a different amount or currency than the invoice.',
        ],
        'confirmed in another currency' => [
            fn ($gateway) => $gateway->willConfirm(150000, 'USD'),
            'failed',
            'The gateway confirmed a different amount or currency than the invoice.',
        ],
        'confirmed without an amount' => [
            fn ($gateway) => $gateway->answer = new VerifiedPayment(PaymentStatus::Succeeded),
            'failed',
            'The gateway confirmed a different amount or currency than the invoice.',
        ],
    ]);

    it('can be followed by another attempt that succeeds', function () {
        $declined = ($this->begin)();
        $this->gateway->willFail();
        ($this->comeBack)($declined);

        $this->gateway->answer = null;
        $second = ($this->begin)();
        ($this->comeBack)($second);

        expect(($this->fresh)($declined)->status)->toBe(PaymentStatus::Failed)
            ->and(($this->fresh)($second)->status)->toBe(PaymentStatus::Succeeded)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Paid);
    });

    it('stays pending while the gateway cannot be reached, and settles when it can', function () {
        $payment = ($this->begin)();
        $this->gateway->verificationFailure = new GatewayException('Connection timed out.');

        ($this->comeBack)($payment)
            ->assertStatus(303)
            ->assertRedirect("http://acme.localhost/billing?payment={$payment->reference}");
        ($this->notify)(['reference' => $payment->reference])->assertOk()->assertContent('OK');

        expect(($this->fresh)($payment)->status)->toBe(PaymentStatus::Pending)
            ->and(($this->fresh)($payment)->failed_at)->toBeNull()
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open)
            ->and($this->auditTrail($this->acme, 'billing.payment_failed'))->toBeEmpty();

        // The billing page is honest about it: still waiting.
        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/billing?payment='.$payment->reference)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returnedPayment.status', 'pending'));

        // The gateway notifies again once it is back.
        $this->gateway->verificationFailure = null;
        ($this->notify)(['reference' => $payment->reference]);

        expect(($this->fresh)($payment)->status)->toBe(PaymentStatus::Succeeded)
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Paid);
    });

    it('stays pending while the gateway has not decided, and keeps what it said', function () {
        $payment = ($this->begin)();
        $this->gateway->answer = new VerifiedPayment(PaymentStatus::Pending, failureReason: 'Waiting for the bank.');

        ($this->comeBack)($payment);

        expect(($this->fresh)($payment)->status)->toBe(PaymentStatus::Pending)
            ->and(($this->fresh)($payment)->failure_reason)->toBe('Waiting for the bank.')
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Open);
    });

    it('stays pending when its gateway has lost its credentials', function () {
        $payment = ($this->begin)();
        $this->gateway->configured = false;

        ($this->comeBack)($payment)->assertStatus(303);

        expect($this->gateway->verifications)->toBe([])
            ->and(($this->fresh)($payment)->status)->toBe(PaymentStatus::Pending);
    });
});

describe('money that arrives for an invoice that is no longer open', function () {
    it('is kept as a successful payment and flagged when the invoice was withdrawn meanwhile', function () {
        $payment = ($this->begin)();

        // While the payer is at the gateway.
        $this->inTenant($this->acme, function (): void {
            app(InvoiceIssuer::class)->voidOpen();
        });

        ($this->comeBack)($payment)->assertStatus(303);

        $payment = ($this->fresh)($payment);

        // The money did arrive: it is never rewritten as failed.
        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->paid_at)->not->toBeNull()
            ->and(($this->invoiceNow)()->status)->toBe(InvoiceStatus::Void)
            ->and(($this->invoiceNow)()->paid_at)->toBeNull()
            // Nothing was bought with it.
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'billing.subscription_activated'))->toBeEmpty();

        $flag = $this->auditTrail($this->acme, 'billing.payment_unapplied')->sole();

        expect($flag->subject_id)->toBe($payment->id)
            ->and($flag->properties)->toEqual(['invoice' => $this->invoice->number, 'invoice_status' => 'void']);
    });

    it('is flagged as well when another payment had already settled the invoice', function () {
        $first = ($this->begin)();
        $second = ($this->begin)();

        $this->gateway->willConfirm(150000, 'BDT', 'txn-one');
        ($this->comeBack)($first);

        $this->gateway->willConfirm(150000, 'BDT', 'txn-two');
        ($this->comeBack)($second);

        expect(($this->fresh)($first)->status)->toBe(PaymentStatus::Succeeded)
            ->and(($this->fresh)($second)->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.subscription_activated'))->toHaveCount(1);

        $flag = $this->auditTrail($this->acme, 'billing.payment_unapplied')->sole();

        expect($flag->subject_id)->toBe($second->id)
            ->and($flag->properties)->toEqual(['invoice' => $this->invoice->number, 'invoice_status' => 'paid']);
    });
});
