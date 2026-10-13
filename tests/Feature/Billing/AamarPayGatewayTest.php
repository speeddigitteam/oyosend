<?php

declare(strict_types=1);

use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Gateways\AamarPayGateway;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Gateways\PaymentRedirect;
use App\Core\Billing\Gateways\PaymentRequest;
use App\Core\Billing\Gateways\VerifiedPayment;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\PaymentProcessor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * The aamarPay gateway against shaped answers of its JSON API: session
 * (`jsonpost.php`) and transaction verification (`api/v1/trxcheck/request.php`).
 * The shapes follow aamarPay's published documentation but have NOT been
 * recorded from its sandbox; see docs/BILLING.md.
 *
 * Nothing here reaches aamarPay: a request that is not faked fails the test.
 */
const AAMAR_STORE_ID = 'oyosendstore01';
const AAMAR_SIGNATURE_KEY = 'f3a91c0d7e2b4a58b6d1c9e04fa27b13';
const AAMAR_REFERENCE = '01JNZ8Q6X3V9T4M2K7H5C1B0AE';

const AAMAR_SANDBOX = 'https://sandbox.aamarpay.com';
const AAMAR_LIVE = 'https://secure.aamarpay.com';

beforeEach(function () {
    Http::preventStrayRequests();

    $this->gateway = fn (bool $sandbox = true, ?string $storeId = AAMAR_STORE_ID, ?string $key = AAMAR_SIGNATURE_KEY): AamarPayGateway => new AamarPayGateway(
        app(Factory::class),
        $storeId,
        $key,
        $sandbox,
    );

    $this->payment = fn (int $amount = 150000, string $currency = 'BDT'): Payment => new Payment([
        'gateway' => 'aamarpay',
        'status' => PaymentStatus::Pending,
        'amount' => $amount,
        'currency' => $currency,
        'reference' => AAMAR_REFERENCE,
    ]);

    $this->request = fn (array $overrides = []): PaymentRequest => new PaymentRequest(...[
        'description' => 'Invoice INV-2026-000042',
        'customerName' => 'Acme Trading Limited',
        'customerEmail' => 'accounts@acme.example',
        'customerPhone' => '+8801700000000',
        'address' => 'House 12, Road 5, Dhanmondi',
        'city' => 'Dhaka',
        'postalCode' => '1205',
        'country' => 'BD',
        'returnUrl' => 'http://acme.localhost/billing/payments/'.AAMAR_REFERENCE.'/return',
        'notifyUrl' => 'http://acme.localhost/billing/gateways/aamarpay/notify',
        ...$overrides,
    ]);

    $this->session = fn (array $overrides = []): array => [
        'result' => 'true',
        'payment_url' => AAMAR_SANDBOX.'/paynow.php?track=AAM1700000000ABCDEF',
        ...$overrides,
    ];

    $this->transaction = fn (array $overrides = []): array => [
        'pg_txnid' => 'AAM1700000000ABCDEF',
        'mer_txnid' => AAMAR_REFERENCE,
        'risk_title' => 'Safe',
        'risk_level' => '0',
        'cus_name' => 'Acme Trading Limited',
        'cus_email' => 'accounts@acme.example',
        'cus_phone' => '+8801700000000',
        'amount' => '1500.00',
        'amount_bdt' => '1500.00',
        'currency' => 'BDT',
        'currency_merchant' => 'BDT',
        'pay_status' => 'Successful',
        'status_code' => '2',
        'status_title' => 'Successful',
        'bank_trxid' => '9A6B0CD1EF',
        'payment_type' => 'bKash',
        'payment_processor' => 'bKash',
        'store_amount' => '1462.50',
        'cardnumber' => '',
        'date_processed' => '2026-03-10 15:04:21',
        ...$overrides,
    ];

    $this->fakeSession = fn (mixed $answer, int $status = 200) => Http::fake([
        '*/jsonpost.php' => $answer instanceof Closure || $answer instanceof PromiseInterface || is_object($answer) ? $answer : Http::response($answer, $status),
    ]);

    $this->fakeVerify = fn (mixed $answer, int $status = 200) => Http::fake([
        '*/api/v1/trxcheck/request.php*' => is_object($answer) ? $answer : Http::response($answer, $status),
    ]);

    $this->sent = fn (): Request => Http::recorded()->sole()[0];

    $this->failure = function (Closure $callback): GatewayException {
        try {
            $callback();
        } catch (GatewayException $exception) {
            return $exception;
        }

        $this->fail('The gateway did not fail.');
    };
});

describe('configuration', function () {
    it('identifies itself', function () {
        expect(($this->gateway)()->key())->toBe('aamarpay')
            ->and(($this->gateway)()->name())->toBe('aamarPay');
    });

    it('is configured only with a store id and a signature key', function (?string $storeId, ?string $key, bool $configured) {
        expect(($this->gateway)(true, $storeId, $key)->isConfigured())->toBe($configured);
    })->with([
        'both' => [AAMAR_STORE_ID, AAMAR_SIGNATURE_KEY, true],
        'neither' => [null, null, false],
        'no key' => [AAMAR_STORE_ID, null, false],
        'no store id' => [null, AAMAR_SIGNATURE_KEY, false],
        'an empty key' => [AAMAR_STORE_ID, '', false],
        'an empty store id' => ['', AAMAR_SIGNATURE_KEY, false],
    ]);

    it('does nothing at all without credentials', function () {
        Http::fake();

        $gateway = ($this->gateway)(true, null, null);

        expect(($this->failure)(fn () => $gateway->initiate(($this->payment)(), ($this->request)()))->getMessage())->toBe('aamarPay is not configured.')
            ->and(($this->failure)(fn () => $gateway->verify(($this->payment)(), []))->getMessage())->toBe('aamarPay is not configured.');

        Http::assertNothingSent();
    });

    it('takes BDT only, in any case', function () {
        $gateway = ($this->gateway)();

        foreach (['BDT', 'bdt'] as $currency) {
            expect($gateway->supportsCurrency($currency))->toBeTrue($currency);
        }

        foreach (['USD', 'EUR', 'INR', 'JPY', '', 'BD'] as $currency) {
            expect($gateway->supportsCurrency($currency))->toBeFalse($currency);
        }
    });

    it('is registered from the configuration, and offered only once that holds credentials', function () {
        config(['platform.gateways.aamarpay' => ['store_id' => null, 'signature_key' => null, 'sandbox' => true]]);
        app()->forgetInstance(GatewayRegistry::class);

        $registry = app(GatewayRegistry::class);

        expect($registry->find('aamarpay'))->toBeInstanceOf(AamarPayGateway::class)
            ->and($registry->find('aamarpay')->isConfigured())->toBeFalse()
            ->and(array_map(fn ($gateway): string => $gateway->key(), $registry->usable('BDT')))->not->toContain('aamarpay');

        config(['platform.gateways.aamarpay' => ['store_id' => AAMAR_STORE_ID, 'signature_key' => AAMAR_SIGNATURE_KEY, 'sandbox' => true]]);
        app()->forgetInstance(GatewayRegistry::class);

        $registry = app(GatewayRegistry::class);

        expect(array_map(fn ($gateway): string => $gateway->key(), $registry->usable('BDT')))->toContain('aamarpay')
            ->and(array_map(fn ($gateway): string => $gateway->key(), $registry->usable('USD')))->not->toContain('aamarpay');
    });
});

describe('opening a payment', function () {
    it('posts the documented fields as JSON and answers where to send the payer', function () {
        ($this->fakeSession)(($this->session)());

        $redirect = ($this->gateway)()->initiate(($this->payment)(), ($this->request)());

        expect($redirect)->toBeInstanceOf(PaymentRedirect::class)
            ->and($redirect->url)->toBe(AAMAR_SANDBOX.'/paynow.php?track=AAM1700000000ABCDEF');

        $request = ($this->sent)();

        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe(AAMAR_SANDBOX.'/jsonpost.php')
            ->and($request->isJson())->toBeTrue()
            ->and($request->data())->toBe([
                'store_id' => AAMAR_STORE_ID,
                'signature_key' => AAMAR_SIGNATURE_KEY,
                'tran_id' => AAMAR_REFERENCE,
                // A decimal string made from integer minor units.
                'amount' => '1500.00',
                'currency' => 'BDT',
                'desc' => 'Invoice INV-2026-000042',
                'cus_name' => 'Acme Trading Limited',
                'cus_email' => 'accounts@acme.example',
                'cus_phone' => '+8801700000000',
                'cus_add1' => 'House 12, Road 5, Dhanmondi',
                'cus_city' => 'Dhaka',
                'cus_postcode' => '1205',
                'cus_country' => 'BD',
                'success_url' => 'http://acme.localhost/billing/payments/'.AAMAR_REFERENCE.'/return',
                'fail_url' => 'http://acme.localhost/billing/payments/'.AAMAR_REFERENCE.'/return',
                'cancel_url' => 'http://acme.localhost/billing/payments/'.AAMAR_REFERENCE.'/return',
                'type' => 'json',
            ]);
    });

    it('talks to the sandbox or to the live host, as configured', function (bool $sandbox, string $host) {
        ($this->fakeSession)(($this->session)(['payment_url' => $host.'/paynow.php?track=X']));

        $redirect = ($this->gateway)($sandbox)->initiate(($this->payment)(), ($this->request)());

        expect(($this->sent)()->url())->toBe($host.'/jsonpost.php')
            ->and($redirect->url)->toBe($host.'/paynow.php?track=X');
    })->with([
        'sandbox' => [true, AAMAR_SANDBOX],
        'live' => [false, AAMAR_LIVE],
    ]);

    it('writes the amount as a decimal string without a float', function (int $amount, string $expected) {
        ($this->fakeSession)(($this->session)());

        ($this->gateway)()->initiate(($this->payment)($amount), ($this->request)());

        expect(($this->sent)()->data()['amount'])->toBe($expected);
    })->with([
        'a round amount' => [150000, '1500.00'],
        'paisa' => [150050, '1500.50'],
        'five paisa' => [5, '0.05'],
        'no thousands separator' => [123456789, '1234567.89'],
    ]);

    it('refuses a currency other than BDT before asking anybody', function () {
        Http::fake();

        $exception = ($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(2500, 'USD'), ($this->request)()));

        expect($exception->getMessage())->toBe('aamarPay accepts BDT only.');

        Http::assertNothingSent();
    });

    it('cuts what is longer than aamarPay accepts, by characters', function () {
        ($this->fakeSession)(($this->session)());

        ($this->gateway)()->initiate(($this->payment)(), ($this->request)([
            'description' => str_repeat('ইনভয়েস ', 60),
            'customerName' => str_repeat('আ', 60),
        ]));

        $data = ($this->sent)()->data();

        expect(mb_strlen($data['desc']))->toBe(255)
            ->and($data['cus_name'])->toBe(str_repeat('আ', 50));
    });

    it('fails when aamarPay refuses the session', function (mixed $answer, string $message) {
        ($this->fakeSession)($answer);

        expect(($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)()))->getMessage())->toBe($message);
    })->with([
        'a refusal with a reason' => [['result' => 'false', 'error' => 'Invalid store'], 'aamarPay refused the payment session: Invalid store'],
        'a plain text refusal' => ['Signature key is not valid', 'aamarPay refused the payment session: Signature key is not valid'],
        'true, but nowhere to send the payer' => [['result' => 'true', 'payment_url' => ''], 'aamarPay refused the payment session: true'],
        'a URL, but not a success' => [['result' => 'false', 'payment_url' => 'https://evil.example'], 'aamarPay refused the payment session: false'],
        'an answer that is not about a session' => [['message' => 'Hello'], 'aamarPay refused the payment session: Hello'],
    ]);

    it('fails on an HTTP error', function (int $status) {
        ($this->fakeSession)(($this->session)(), $status);

        expect(($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)()))->getMessage())
            ->toBe("aamarPay answered with HTTP {$status}.");
    })->with([400, 403, 500, 503]);

    it('fails when aamarPay cannot be reached', function () {
        ($this->fakeSession)(Http::failedConnection());

        $exception = ($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)()));

        expect($exception->getMessage())->toBe('aamarPay could not be reached.')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
    });

    it('asks for the company details it needs instead of making them up', function (string $missing) {
        Http::fake();

        $exception = ($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)([$missing => null])));

        expect($exception->isMissingBillingDetails())->toBeTrue();

        Http::assertNothingSent();
    })->with(['customerPhone', 'address', 'city', 'postalCode', 'country']);
});

describe('verifying', function () {
    it('asks the verification API by our own reference and accepts a successful transaction', function () {
        ($this->fakeVerify)(($this->transaction)());

        $verified = ($this->gateway)()->verify(($this->payment)(), [
            // Everything that arrives is ignored: it is what the browser claims.
            'pay_status' => 'Successful',
            'mer_txnid' => 'SOMETHING-ELSE',
            'amount' => '1.00',
        ]);

        $request = ($this->sent)();

        expect($request->method())->toBe('GET')
            ->and(strtok($request->url(), '?'))->toBe(AAMAR_SANDBOX.'/api/v1/trxcheck/request.php')
            ->and($request->data())->toEqual([
                'request_id' => AAMAR_REFERENCE,
                'store_id' => AAMAR_STORE_ID,
                'signature_key' => AAMAR_SIGNATURE_KEY,
                'type' => 'json',
            ]);

        expect($verified)->toBeInstanceOf(VerifiedPayment::class)
            ->and($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->amount)->toBe(150000)
            ->and($verified->currency)->toBe('BDT')
            ->and($verified->gatewayReference)->toBe('AAM1700000000ABCDEF')
            ->and($verified->method)->toBe('bKash')
            ->and($verified->failureReason)->toBeNull()
            ->and($verified->details)->toBe([
                'pg_txnid' => 'AAM1700000000ABCDEF',
                'bank_trxid' => '9A6B0CD1EF',
                'payment_type' => 'bKash',
                'payment_processor' => 'bKash',
                'store_amount' => '1462.50',
                'date_processed' => '2026-03-10 15:04:21',
                'risk_title' => 'Safe',
            ]);
    });

    it('asks the live host when it is not in sandbox mode', function () {
        ($this->fakeVerify)(($this->transaction)());

        ($this->gateway)(false)->verify(($this->payment)(), []);

        expect(strtok(($this->sent)()->url(), '?'))->toBe(AAMAR_LIVE.'/api/v1/trxcheck/request.php');
    });

    it('reports a transaction that did not go through', function (string $status, PaymentStatus $expected) {
        ($this->fakeVerify)(($this->transaction)(['pay_status' => $status, 'status_code' => '7', 'status_title' => 'Declined by the issuer']));

        $verified = ($this->gateway)()->verify(($this->payment)(), []);

        expect($verified->status)->toBe($expected)
            ->and($verified->failureReason)->toBe('Declined by the issuer')
            ->and($verified->amount)->toBeNull();
    })->with([
        'Failed' => ['Failed', PaymentStatus::Failed],
        'failed, in lower case' => ['failed', PaymentStatus::Failed],
        'Cancelled' => ['Cancelled', PaymentStatus::Canceled],
        'Pending' => ['Pending', PaymentStatus::Pending],
        'a status this build does not know' => ['Processing', PaymentStatus::Pending],
    ]);

    it('decides nothing while the transaction is unknown', function (mixed $answer) {
        ($this->fakeVerify)($answer);

        $verified = ($this->gateway)()->verify(($this->payment)(), ['pay_status' => 'Successful']);

        expect($verified->status)->toBe(PaymentStatus::Pending)
            ->and($verified->amount)->toBeNull()
            ->and($verified->gatewayReference)->toBeNull();
    })->with([
        'an empty answer' => [[]],
        'an error object' => [['status_code' => '3', 'status_title' => 'Transaction not found']],
        'no pay_status' => [['mer_txnid' => AAMAR_REFERENCE]],
        'not JSON' => ['<html>Service Unavailable</html>'],
    ]);

    it('does not accept a Successful text that its own status code contradicts', function () {
        ($this->fakeVerify)(($this->transaction)(['status_code' => '1']));

        expect(($this->gateway)()->verify(($this->payment)(), [])->status)->toBe(PaymentStatus::Pending);
    });

    it('fails a payment that was answered for another transaction', function () {
        ($this->fakeVerify)(($this->transaction)(['mer_txnid' => '01JNZ8Q6X3V9T4M2K7H5C1B0AF']));

        $verified = ($this->gateway)()->verify(($this->payment)(), []);

        expect($verified->status)->toBe(PaymentStatus::Failed)
            ->and($verified->failureReason)->toBe('The gateway answered about a different transaction.')
            ->and($verified->amount)->toBeNull();
    });

    it('reports the amount and currency exactly as aamarPay holds them, to be compared by the processor', function (array $overrides, ?int $amount, string $currency) {
        ($this->fakeVerify)(($this->transaction)($overrides));

        $verified = ($this->gateway)()->verify(($this->payment)(), []);

        expect($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->amount)->toBe($amount)
            ->and($verified->currency)->toBe($currency);
    })->with([
        'a lower amount' => [['amount' => '15.00'], 1500, 'BDT'],
        'one paisa short' => [['amount' => '1499.99'], 149999, 'BDT'],
        'extra decimals are cut, not rounded' => [['amount' => '1499.999'], 149999, 'BDT'],
        'a JSON number' => [['amount' => 1500.5], 150050, 'BDT'],
        'another currency' => [['currency_merchant' => 'USD', 'currency' => 'USD'], 150000, 'USD'],
        'a thousands separator is not an amount' => [['amount' => '1,500.00'], null, 'BDT'],
        'no amount' => [['amount' => ''], null, 'BDT'],
    ]);

    it('fails, to be asked again later, on an HTTP error or when aamarPay cannot be reached', function () {
        ($this->fakeVerify)(($this->transaction)(), 503);

        expect(($this->failure)(fn () => ($this->gateway)()->verify(($this->payment)(), []))->getMessage())
            ->toBe('aamarPay answered with HTTP 503.');

        Http::swap(new Factory);
        Http::preventStrayRequests();
        ($this->fakeVerify)(Http::failedConnection());

        $exception = ($this->failure)(fn () => (new AamarPayGateway(Http::getFacadeRoot(), AAMAR_STORE_ID, AAMAR_SIGNATURE_KEY, true))->verify(($this->payment)(), []));

        expect($exception->getMessage())->toBe('aamarPay could not be reached.')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
    });
});

describe('the reference in a notification', function () {
    it('is the mer_txnid, when it is text', function (array $payload, ?string $expected) {
        Http::fake();

        expect(($this->gateway)()->referenceFrom($payload))->toBe($expected);

        Http::assertNothingSent();
    })->with([
        'a callback' => [['mer_txnid' => AAMAR_REFERENCE, 'pay_status' => 'Successful'], AAMAR_REFERENCE],
        'no mer_txnid' => [['pay_status' => 'Successful'], null],
        'an empty mer_txnid' => [['mer_txnid' => ''], null],
        'a list' => [['mer_txnid' => [AAMAR_REFERENCE]], null],
        'nothing' => [[], null],
    ]);
});

describe('credentials', function () {
    it('never appear in an error or in what is kept about a transaction', function () {
        $leaky = ($this->transaction)([
            'store_id' => AAMAR_STORE_ID,
            'signature_key' => AAMAR_SIGNATURE_KEY,
            'status_title' => 'Declined, key '.AAMAR_SIGNATURE_KEY,
            'risk_title' => 'store '.AAMAR_STORE_ID,
        ]);

        $run = function (array $fakes, Closure $call): mixed {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake($fakes);

            $gateway = new AamarPayGateway(Http::getFacadeRoot(), AAMAR_STORE_ID, AAMAR_SIGNATURE_KEY, true);

            try {
                return $call($gateway);
            } catch (GatewayException $exception) {
                return $exception;
            }
        };

        $outcomes = [
            'session refused, echoing the key' => $run(
                ['*/jsonpost.php' => Http::response(['result' => 'false', 'error' => 'Bad signature '.AAMAR_SIGNATURE_KEY])],
                fn (AamarPayGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)()),
            ),
            'session HTTP error' => $run(
                ['*/jsonpost.php' => Http::response('Forbidden', 403)],
                fn (AamarPayGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)()),
            ),
            'session unreachable' => $run(
                ['*/jsonpost.php' => Http::failedConnection()],
                fn (AamarPayGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)()),
            ),
            'verification HTTP error' => $run(
                ['*/api/v1/trxcheck/request.php*' => Http::response('Bad gateway', 502)],
                fn (AamarPayGateway $gateway) => $gateway->verify(($this->payment)(), []),
            ),
            'verification unreachable' => $run(
                ['*/api/v1/trxcheck/request.php*' => Http::failedConnection()],
                fn (AamarPayGateway $gateway) => $gateway->verify(($this->payment)(), []),
            ),
            'successful' => $run(
                ['*/api/v1/trxcheck/request.php*' => Http::response($leaky)],
                fn (AamarPayGateway $gateway) => $gateway->verify(($this->payment)(), []),
            ),
            'failed' => $run(
                ['*/api/v1/trxcheck/request.php*' => Http::response([...$leaky, 'pay_status' => 'Failed'])],
                fn (AamarPayGateway $gateway) => $gateway->verify(($this->payment)(), []),
            ),
        ];

        foreach ($outcomes as $case => $outcome) {
            $kept = match (true) {
                $outcome instanceof GatewayException => $outcome->getMessage(),
                $outcome instanceof PaymentRedirect => json_encode([$outcome->url, $outcome->details]),
                $outcome instanceof VerifiedPayment => json_encode(get_object_vars($outcome)),
            };

            expect($kept)->not->toContain(AAMAR_STORE_ID, AAMAR_SIGNATURE_KEY);
        }

        expect(array_map(fn ($outcome): string => $outcome::class, $outcomes))->toBe([
            'session refused, echoing the key' => GatewayException::class,
            'session HTTP error' => GatewayException::class,
            'session unreachable' => GatewayException::class,
            'verification HTTP error' => GatewayException::class,
            'verification unreachable' => GatewayException::class,
            'successful' => VerifiedPayment::class,
            'failed' => VerifiedPayment::class,
        ]);
    });

    it('keeps no card number, only how the payment was made', function () {
        ($this->fakeVerify)(($this->transaction)(['cardnumber' => '432155******3964']));

        $verified = ($this->gateway)()->verify(($this->payment)(), []);

        expect(json_encode(get_object_vars($verified)))->not->toContain('432155', '3964');
    });
});

describe('through the payment processor', function () {
    beforeEach(function () {
        Mail::fake();

        $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

        $this->acme = $this->createTenant('acme', ['name' => 'Acme']);
        $this->subscribe($this->acme, $this->createPlan(['monthly_amount' => 150000]), withTrial: false);
        $this->invoice = $this->openInvoiceOf($this->acme);

        $this->configure = function (?string $storeId = AAMAR_STORE_ID, ?string $key = AAMAR_SIGNATURE_KEY): void {
            config(['platform.gateways.aamarpay' => ['store_id' => $storeId, 'signature_key' => $key, 'sandbox' => true]]);

            app()->forgetInstance(GatewayRegistry::class);
        };

        $this->profile = [
            'legal_name' => 'Acme Trading Limited',
            'email' => 'accounts@acme.example',
            'phone' => '+8801700000000',
            'address' => 'House 12, Road 5, Dhanmondi',
            'city' => 'Dhaka',
            'postal_code' => '1205',
            'country' => 'BD',
        ];

        $this->begin = fn (): PaymentRedirect => $this->inTenant(
            $this->acme,
            fn (): PaymentRedirect => app(PaymentProcessor::class)->begin($this->invoice, 'aamarpay', $this->ownerOf($this->acme)),
        );

        $this->open = function (): Payment {
            ($this->configure)();
            $this->acme->update($this->profile);
            ($this->fakeSession)(($this->session)());
            ($this->begin)();

            return $this->paymentsOf($this->acme)->sole();
        };
    });

    it('is refused, and asks nobody anything, while it has no credentials', function (?string $storeId, ?string $key) {
        Http::fake();

        ($this->configure)($storeId, $key);
        $this->acme->update($this->profile);

        try {
            ($this->begin)();
            $this->fail('The payment was not refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['gateway' => ['This payment method is not available.']]);
        }

        expect($this->paymentsOf($this->acme))->toBeEmpty();

        Http::assertNothingSent();
    })->with([
        'no credentials' => [null, null],
        'a store id only' => [AAMAR_STORE_ID, null],
        'empty credentials' => ['', ''],
    ]);

    it('opens the payment for the invoice and settles it on the verification answer', function () {
        $payment = ($this->open)();
        $posted = ($this->sent)()->data();

        expect($payment->gateway)->toBe('aamarpay')
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($posted['tran_id'])->toBe($payment->reference)
            ->and($posted['amount'])->toBe('1500.00')
            ->and($posted['currency'])->toBe('BDT')
            ->and(json_encode($payment->getAttributes()))->not->toContain(AAMAR_STORE_ID, AAMAR_SIGNATURE_KEY)
            ->and(json_encode($this->auditTrail($this->acme)))->not->toContain(AAMAR_STORE_ID, AAMAR_SIGNATURE_KEY);

        ($this->fakeVerify)(($this->transaction)(['mer_txnid' => $payment->reference]));

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment, []));

        expect($confirmed->status)->toBe(PaymentStatus::Succeeded)
            ->and($confirmed->gateway_reference)->toBe('AAM1700000000ABCDEF')
            ->and($confirmed->method)->toBe('bKash')
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(json_encode($this->auditTrail($this->acme)))->not->toContain(AAMAR_STORE_ID, AAMAR_SIGNATURE_KEY);

        // Idempotent: a repeat asks nobody and changes nothing.
        $again = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($confirmed->refresh(), []));

        expect($again->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->auditTrail($this->acme, 'billing.payment_received'))->toHaveCount(1);
    });

    it('fails the payment when aamarPay verifies another amount than the invoice', function () {
        $payment = ($this->open)();

        ($this->fakeVerify)(($this->transaction)(['mer_txnid' => $payment->reference, 'amount' => '15.00']));

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment, []));

        expect($confirmed->status)->toBe(PaymentStatus::Failed)
            ->and($confirmed->failure_reason)->toBe('The gateway confirmed a different amount or currency than the invoice.')
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
    });

    it('fails the payment when aamarPay verifies another currency than the invoice', function () {
        $payment = ($this->open)();

        ($this->fakeVerify)(($this->transaction)(['mer_txnid' => $payment->reference, 'currency_merchant' => 'USD', 'currency' => 'USD']));

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment, []));

        expect($confirmed->status)->toBe(PaymentStatus::Failed)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
    });

    it('leaves the payment pending for an unknown transaction and when aamarPay cannot be reached', function () {
        $payment = ($this->open)();

        ($this->fakeVerify)(['status_code' => '3', 'status_title' => 'Transaction not found']);

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment, []));

        expect($confirmed->status)->toBe(PaymentStatus::Pending);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        ($this->fakeVerify)(Http::failedConnection());

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment->refresh(), []));

        expect($confirmed->status)->toBe(PaymentStatus::Pending)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
    });

    it('does not settle on a forged return or notification', function (string $route) {
        $payment = ($this->open)();

        // The verification API says the payer never paid; the forged payload says otherwise.
        ($this->fakeVerify)(($this->transaction)(['mer_txnid' => $payment->reference, 'pay_status' => 'Pending', 'status_code' => '1']));

        $forged = [
            'mer_txnid' => $payment->reference,
            'pay_status' => 'Successful',
            'status_code' => '2',
            'amount' => '1500.00',
            'currency' => 'BDT',
            'pg_txnid' => 'AAMFORGED',
        ];

        $response = $route === 'return'
            ? $this->startNewBrowserSession()->call('POST', "http://acme.localhost/billing/payments/{$payment->reference}/return", $forged)
            : $this->startNewBrowserSession()->post('http://acme.localhost/billing/gateways/aamarpay/notify', $forged);

        // Stateless: no cookie of any kind.
        expect($response->headers->getCookies())->toBe([])
            ->and(Payment::forTenant($this->acme)->findOrFail($payment->id)->status)->toBe(PaymentStatus::Pending)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
    })->with(['return', 'notification']);

    it('settles on a return only because the verification API said so', function () {
        $payment = ($this->open)();

        ($this->fakeVerify)(($this->transaction)(['mer_txnid' => $payment->reference]));

        $response = $this->startNewBrowserSession()->call('POST', "http://acme.localhost/billing/payments/{$payment->reference}/return", ['mer_txnid' => $payment->reference]);

        expect($response->headers->getCookies())->toBe([])
            ->and(Payment::forTenant($this->acme)->findOrFail($payment->id)->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
    });
});
