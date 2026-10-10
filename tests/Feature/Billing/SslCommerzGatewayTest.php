<?php

declare(strict_types=1);

use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Exceptions\GatewayException;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Gateways\PaymentRedirect;
use App\Core\Billing\Gateways\PaymentRequest;
use App\Core\Billing\Gateways\SslCommerzGateway;
use App\Core\Billing\Gateways\VerifiedPayment;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\PaymentProcessor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * The SSLCommerz gateway against recorded answers of its API (v4). Field
 * names are the ones in https://developer.sslcommerz.com/doc/v4/ - the
 * session API (`gwprocess/v4/api.php`), the order validation API
 * (`validator/api/validationserverAPI.php`) and the transaction query API
 * (`validator/api/merchantTransIDvalidationAPI.php`).
 *
 * Nothing here reaches SSLCommerz: a request that is not faked fails the test.
 */
const SSLC_STORE_ID = 'oyosend5f1a2b3c';
const SSLC_STORE_PASSWORD = 'oyosend5f1a2b3c@ssl&secret';
const SSLC_REFERENCE = '01JNZ8Q6X3V9T4M2K7H5C1B0AE';

const SSLC_SANDBOX = 'https://sandbox.sslcommerz.com';
const SSLC_LIVE = 'https://securepay.sslcommerz.com';

beforeEach(function () {
    Http::preventStrayRequests();

    $this->gateway = fn (bool $sandbox = true, ?string $storeId = SSLC_STORE_ID, ?string $storePassword = SSLC_STORE_PASSWORD): SslCommerzGateway => new SslCommerzGateway(
        app(Factory::class),
        $storeId,
        $storePassword,
        $sandbox,
    );

    /** A payment as PaymentProcessor hands it to a gateway. Not saved: the gateway only reads it. */
    $this->payment = fn (int $amount = 150000, string $currency = 'BDT'): Payment => new Payment([
        'gateway' => 'sslcommerz',
        'status' => PaymentStatus::Pending,
        'amount' => $amount,
        'currency' => $currency,
        'reference' => SSLC_REFERENCE,
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
        'returnUrl' => 'http://acme.localhost/billing/payments/'.SSLC_REFERENCE.'/return',
        'notifyUrl' => 'http://acme.localhost/billing/gateways/sslcommerz/notify',
        ...$overrides,
    ]);

    /** What the session API answers when it opens a payment. */
    $this->session = fn (array $overrides = []): array => [
        'status' => 'SUCCESS',
        'failedreason' => '',
        'sessionkey' => 'A8EF93B75B8107E4F36049E80B4F9149',
        'gw' => ['visa' => 'city_visa,ebl_visa', 'internetbanking' => 'city,bkash,nagad'],
        'redirectGatewayURL' => SSLC_SANDBOX.'/gwprocess/v4/bankgw/indexhtml.php?mamount=1500.00&sessionkey=A8EF93B75B8107E4F36049E80B4F9149',
        'GatewayPageURL' => SSLC_SANDBOX.'/EasyCheckOut/testcdea8ef93b75b8107e4f36049e80b4f9149',
        'storeBanner' => '',
        'storeLogo' => '',
        'is_direct_pay_enable' => '0',
        ...$overrides,
    ];

    /** One transaction, as the validation API and (per attempt) the query API describe it. */
    $this->transaction = fn (array $overrides = []): array => [
        'status' => 'VALID',
        'tran_date' => '2026-03-10 15:04:21',
        'tran_id' => SSLC_REFERENCE,
        'val_id' => '2603101504210kVvTgEXyq4EEPE',
        'amount' => '1500.00',
        'store_amount' => '1462.50',
        'currency' => 'BDT',
        'bank_tran_id' => '260310150421IxTtHRMCPhU6m5A',
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '432155******3964',
        'card_issuer' => 'STANDARD CHARTERED BANK',
        'card_brand' => 'VISA',
        'card_issuer_country' => 'Bangladesh',
        'card_issuer_country_code' => 'BD',
        'currency_type' => 'BDT',
        'currency_amount' => '1500.00',
        'currency_rate' => '1.0000',
        'base_fair' => '0.00',
        'value_a' => '',
        'value_b' => '',
        'value_c' => '',
        'value_d' => '',
        'emi_instalment' => '0',
        'emi_amount' => '0.00',
        'discount_amount' => '0.00',
        'discount_percentage' => '0.00',
        'discount_remarks' => '',
        'risk_level' => '0',
        'risk_title' => 'Safe',
        'APIConnect' => 'DONE',
        'validated_on' => '2026-03-10 15:04:29',
        'gw_version' => '',
        ...$overrides,
    ];

    /** The query API: every attempt made under one of our references. */
    $this->attempts = fn (array ...$attempts): array => [
        'APIConnect' => 'DONE',
        'no_of_trans_found' => count($attempts),
        'element' => $attempts,
    ];

    $this->fakeSession = fn (array|Closure $answer, int $status = 200) => Http::fake([
        '*/gwprocess/v4/api.php' => is_array($answer) ? Http::response($answer, $status) : $answer,
    ]);

    $this->fakeValidation = fn (mixed $answer, int $status = 200) => Http::fake([
        '*/validator/api/validationserverAPI.php*' => $answer instanceof Closure ? $answer : Http::response($answer, $status),
    ]);

    $this->fakeQuery = fn (mixed $answer, int $status = 200) => Http::fake([
        '*/validator/api/merchantTransIDvalidationAPI.php*' => $answer instanceof Closure ? $answer : Http::response($answer, $status),
    ]);

    /** The one request that was sent. */
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
        expect(($this->gateway)()->key())->toBe('sslcommerz')
            ->and(($this->gateway)()->name())->toBe('SSLCommerz');
    });

    it('is configured only with a store id and a store password', function (?string $storeId, ?string $storePassword, bool $configured) {
        expect(($this->gateway)(true, $storeId, $storePassword)->isConfigured())->toBe($configured);
    })->with([
        'both' => [SSLC_STORE_ID, SSLC_STORE_PASSWORD, true],
        'neither' => [null, null, false],
        'no password' => [SSLC_STORE_ID, null, false],
        'no store id' => [null, SSLC_STORE_PASSWORD, false],
        'an empty password' => [SSLC_STORE_ID, '', false],
        'an empty store id' => ['', SSLC_STORE_PASSWORD, false],
    ]);

    it('does nothing at all without credentials', function () {
        Http::fake();

        $gateway = ($this->gateway)(true, null, null);

        expect(($this->failure)(fn () => $gateway->initiate(($this->payment)(), ($this->request)()))->getMessage())->toBe('SSLCommerz is not configured.')
            ->and(($this->failure)(fn () => $gateway->verify(($this->payment)(), ['val_id' => 'abc']))->getMessage())->toBe('SSLCommerz is not configured.')
            ->and(($this->failure)(fn () => $gateway->verify(($this->payment)(), []))->getMessage())->toBe('SSLCommerz is not configured.');

        Http::assertNothingSent();
    });

    it('takes the currencies SSLCommerz accepts, in any case', function () {
        $gateway = ($this->gateway)();

        foreach (['BDT', 'USD', 'EUR', 'GBP', 'SGD', 'INR', 'MYR', 'bdt', 'usd'] as $currency) {
            expect($gateway->supportsCurrency($currency))->toBeTrue($currency);
        }

        foreach (['JPY', 'AED', 'SAR', 'AUD', 'CAD', 'XXX', '', 'BD'] as $currency) {
            expect($gateway->supportsCurrency($currency))->toBeFalse($currency);
        }
    });

    it('is registered from the configuration, and offered only once that holds credentials', function () {
        config(['platform.gateways.sslcommerz' => ['store_id' => null, 'store_password' => null, 'sandbox' => true]]);
        app()->forgetInstance(GatewayRegistry::class);

        $registry = app(GatewayRegistry::class);

        expect($registry->find('sslcommerz'))->toBeInstanceOf(SslCommerzGateway::class)
            ->and($registry->find('sslcommerz')->isConfigured())->toBeFalse()
            ->and($registry->usable('BDT'))->toBe([]);

        config(['platform.gateways.sslcommerz' => ['store_id' => SSLC_STORE_ID, 'store_password' => SSLC_STORE_PASSWORD, 'sandbox' => true]]);
        app()->forgetInstance(GatewayRegistry::class);

        $registry = app(GatewayRegistry::class);

        expect(array_map(fn ($gateway): string => $gateway->key(), $registry->usable('BDT')))->toBe(['sslcommerz'])
            ->and($registry->usable('JPY'))->toBe([]);
    });
});

describe('opening a payment', function () {
    it('posts the documented fields to the session API and answers where to send the payer', function () {
        ($this->fakeSession)(($this->session)());

        $redirect = ($this->gateway)()->initiate(($this->payment)(), ($this->request)());

        expect($redirect)->toBeInstanceOf(PaymentRedirect::class)
            ->and($redirect->url)->toBe(SSLC_SANDBOX.'/EasyCheckOut/testcdea8ef93b75b8107e4f36049e80b4f9149')
            ->and($redirect->details)->toBe(['sessionkey' => 'A8EF93B75B8107E4F36049E80B4F9149']);

        $request = ($this->sent)();

        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe(SSLC_SANDBOX.'/gwprocess/v4/api.php')
            // Form-encoded, as the API requires - not JSON.
            ->and($request->isForm())->toBeTrue()
            ->and($request->header('Content-Type')[0])->toBe('application/x-www-form-urlencoded')
            ->and($request->data())->toBe([
                'store_id' => SSLC_STORE_ID,
                'store_passwd' => SSLC_STORE_PASSWORD,
                // A decimal string made from integer minor units.
                'total_amount' => '1500.00',
                'currency' => 'BDT',
                // Our own reference: the validation answer is matched against it.
                'tran_id' => SSLC_REFERENCE,
                // The payer comes back to the same place whatever happened; the outcome is asked for, not believed.
                'success_url' => 'http://acme.localhost/billing/payments/'.SSLC_REFERENCE.'/return',
                'fail_url' => 'http://acme.localhost/billing/payments/'.SSLC_REFERENCE.'/return',
                'cancel_url' => 'http://acme.localhost/billing/payments/'.SSLC_REFERENCE.'/return',
                'ipn_url' => 'http://acme.localhost/billing/gateways/sslcommerz/notify',
                'product_name' => 'Invoice INV-2026-000042',
                'product_category' => 'software subscription',
                'product_profile' => 'non-physical-goods',
                'shipping_method' => 'NO',
                'cus_name' => 'Acme Trading Limited',
                'cus_email' => 'accounts@acme.example',
                'cus_phone' => '+8801700000000',
                'cus_add1' => 'House 12, Road 5, Dhanmondi',
                'cus_city' => 'Dhaka',
                'cus_postcode' => '1205',
                'cus_country' => 'BD',
            ]);
    });

    it('talks to the sandbox or to the live host, as configured', function (bool $sandbox, string $host) {
        ($this->fakeSession)(($this->session)(['GatewayPageURL' => $host.'/EasyCheckOut/abc']));

        $redirect = ($this->gateway)($sandbox)->initiate(($this->payment)(), ($this->request)());

        expect(($this->sent)()->url())->toBe($host.'/gwprocess/v4/api.php')
            ->and($redirect->url)->toBe($host.'/EasyCheckOut/abc');
    })->with([
        'sandbox' => [true, SSLC_SANDBOX],
        'live' => [false, SSLC_LIVE],
    ]);

    it('writes the amount as a decimal string of the currency', function (int $amount, string $currency, string $expected) {
        ($this->fakeSession)(($this->session)());

        ($this->gateway)()->initiate(($this->payment)($amount, $currency), ($this->request)());

        expect(($this->sent)()->data()['total_amount'])->toBe($expected)
            ->and(($this->sent)()->data()['currency'])->toBe($currency);
    })->with([
        'a round amount' => [150000, 'BDT', '1500.00'],
        'paisa' => [150050, 'BDT', '1500.50'],
        'five paisa' => [5, 'BDT', '0.05'],
        'no thousands separator' => [123456789, 'BDT', '1234567.89'],
        'dollars' => [2500, 'USD', '25.00'],
    ]);

    it('cuts what is longer than SSLCommerz accepts, by characters', function () {
        ($this->fakeSession)(($this->session)());

        ($this->gateway)()->initiate(($this->payment)(), ($this->request)([
            'description' => str_repeat('ইনভয়েস ', 60),
            'customerName' => str_repeat('আ', 60),
            'address' => str_repeat('Road ', 30),
            'city' => str_repeat('ঢ', 51),
        ]));

        $data = ($this->sent)()->data();

        expect(mb_strlen($data['product_name']))->toBe(255)
            ->and($data['cus_name'])->toBe(str_repeat('আ', 50))
            ->and(mb_strlen($data['cus_add1']))->toBe(50)
            ->and($data['cus_city'])->toBe(str_repeat('ঢ', 50));
    });

    it('fails when SSLCommerz refuses the session', function (array $answer, string $message) {
        ($this->fakeSession)($answer);

        $exception = ($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)()));

        expect($exception->getMessage())->toBe($message)
            ->and($exception->isMissingBillingDetails())->toBeFalse();
    })->with([
        'FAILED, with its reason' => [
            ['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active', 'sessionkey' => '', 'GatewayPageURL' => ''],
            'SSLCommerz refused the payment session: Store Credential Error Or Store is De-active',
        ],
        'FAILED, without a reason' => [
            ['status' => 'FAILED'],
            'SSLCommerz refused the payment session: no reason given',
        ],
        'SUCCESS, but nowhere to send the payer' => [
            ['status' => 'SUCCESS', 'failedreason' => '', 'sessionkey' => 'A8EF', 'GatewayPageURL' => ''],
            'SSLCommerz refused the payment session: ',
        ],
        'SUCCESS, with something that is not a URL' => [
            ['status' => 'SUCCESS', 'sessionkey' => 'A8EF', 'GatewayPageURL' => ['unexpected']],
            'SSLCommerz refused the payment session: no reason given',
        ],
        'an answer that is not about a session' => [
            ['message' => 'Hello'],
            'SSLCommerz refused the payment session: no reason given',
        ],
    ]);

    it('fails when the answer is not JSON', function () {
        Http::fake(['*/gwprocess/v4/api.php' => Http::response('<html><body>Maintenance</body></html>', 200)]);

        expect(($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)()))->getMessage())
            ->toStartWith('SSLCommerz refused the payment session');
    });

    it('fails on an HTTP error', function (int $status) {
        ($this->fakeSession)(($this->session)(), $status);

        expect(($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)()))->getMessage())
            ->toBe("SSLCommerz answered with HTTP {$status}.");
    })->with([400, 401, 403, 404, 500, 502, 503]);

    it('fails when SSLCommerz cannot be reached', function () {
        ($this->fakeSession)(Http::failedConnection());

        $exception = ($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)()));

        expect($exception->getMessage())->toBe('SSLCommerz could not be reached.')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class)
            ->and($exception->isMissingBillingDetails())->toBeFalse();
    });

    it('asks for the company details it needs instead of making them up', function (string $missing) {
        Http::fake();

        $exception = ($this->failure)(fn () => ($this->gateway)()->initiate(($this->payment)(), ($this->request)([$missing => null])));

        expect($exception->isMissingBillingDetails())->toBeTrue()
            ->and($exception->getMessage())->toContain('sslcommerz', 'phone number, address, city, postal code and country');

        Http::assertNothingSent();
    })->with(['customerPhone', 'address', 'city', 'postalCode', 'country']);
});

describe('verifying with a val_id', function () {
    it('asks the order validation API and accepts a VALID transaction with what the payer was charged', function () {
        ($this->fakeValidation)(($this->transaction)());

        $verified = ($this->gateway)()->verify(($this->payment)(), [
            'val_id' => '2603101504210kVvTgEXyq4EEPE',
            // Everything else that arrives is ignored: it is what the browser claims.
            'status' => 'VALID',
            'amount' => '1.00',
            'tran_id' => 'SOMETHING-ELSE',
        ]);

        $request = ($this->sent)();

        expect($request->method())->toBe('GET')
            ->and(strtok($request->url(), '?'))->toBe(SSLC_SANDBOX.'/validator/api/validationserverAPI.php')
            ->and($request->data())->toEqual([
                'val_id' => '2603101504210kVvTgEXyq4EEPE',
                'store_id' => SSLC_STORE_ID,
                'store_passwd' => SSLC_STORE_PASSWORD,
                'v' => '1',
                'format' => 'json',
            ]);

        expect($verified)->toBeInstanceOf(VerifiedPayment::class)
            ->and($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->amount)->toBe(150000)
            ->and($verified->currency)->toBe('BDT')
            ->and($verified->gatewayReference)->toBe('260310150421IxTtHRMCPhU6m5A')
            ->and($verified->method)->toBe('VISA-Dutch Bangla')
            ->and($verified->failureReason)->toBeNull()
            ->and($verified->details)->toBe([
                'val_id' => '2603101504210kVvTgEXyq4EEPE',
                'bank_tran_id' => '260310150421IxTtHRMCPhU6m5A',
                'card_type' => 'VISA-Dutch Bangla',
                'card_brand' => 'VISA',
                'store_amount' => '1462.50',
                'tran_date' => '2026-03-10 15:04:21',
                'risk_level' => '0',
                'risk_title' => 'Safe',
            ]);
    });

    it('asks the live host when it is not in sandbox mode', function () {
        ($this->fakeValidation)(($this->transaction)());

        ($this->gateway)(false)->verify(($this->payment)(), ['val_id' => 'abc']);

        expect(strtok(($this->sent)()->url(), '?'))->toBe(SSLC_LIVE.'/validator/api/validationserverAPI.php');
    });

    it('accepts VALIDATED as well: the same transaction, asked about a second time', function () {
        ($this->fakeValidation)(($this->transaction)(['status' => 'VALIDATED']));

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']);

        expect($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->amount)->toBe(150000);
    });

    it('reads the amount and currency the payer was charged, not the BDT they were settled in', function () {
        ($this->fakeValidation)(($this->transaction)([
            // Settlement: converted to BDT.
            'amount' => '3050.00',
            'currency' => 'BDT',
            // What we asked for.
            'currency_type' => 'USD',
            'currency_amount' => '25.00',
            'currency_rate' => '122.0000',
        ]));

        $verified = ($this->gateway)()->verify(($this->payment)(2500, 'USD'), ['val_id' => 'abc']);

        expect($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->amount)->toBe(2500)
            ->and($verified->currency)->toBe('USD');
    });

    it('reads amounts exactly, however they are written', function (mixed $written, ?int $expected) {
        ($this->fakeValidation)(($this->transaction)(['currency_amount' => $written]));

        expect(($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc'])->amount)->toBe($expected);
    })->with([
        'two decimals' => ['1500.00', 150000],
        'no decimals' => ['1500', 150000],
        'a JSON number' => [1500, 150000],
        'a JSON number with a fraction' => [1500.5, 150050],
        'one paisa short' => ['1499.99', 149999],
        'extra decimals are cut, not rounded up to the full amount' => ['1499.999', 149999],
        'a thousands separator is not an amount' => ['1,500.00', null],
        'a negative amount is not an amount' => ['-1500.00', null],
        'nothing' => ['', null],
    ]);

    it('falls back to `amount` and `currency` when the charged amount is not given', function () {
        $transaction = ($this->transaction)();
        unset($transaction['currency_type'], $transaction['currency_amount']);

        ($this->fakeValidation)($transaction);

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']);

        expect($verified->amount)->toBe(150000)
            ->and($verified->currency)->toBe('BDT');
    });

    it('falls back to the val_id as the reference when the bank gave none', function () {
        ($this->fakeValidation)(($this->transaction)(['bank_tran_id' => '']));

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']);

        expect($verified->gatewayReference)->toBe('2603101504210kVvTgEXyq4EEPE')
            ->and($verified->details)->not->toHaveKey('bank_tran_id');
    });

    it('decides nothing while SSLCommerz does not say paid, failed or cancelled', function (mixed $answer) {
        ($this->fakeValidation)($answer);
        // An answer that names no transaction of ours is not taken as an answer:
        // the gateway then asks about its own reference, where nothing is found yet.
        ($this->fakeQuery)(($this->attempts)());

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']);

        expect($verified->status)->toBe(PaymentStatus::Pending)
            ->and($verified->amount)->toBeNull()
            ->and($verified->gatewayReference)->toBeNull();
    })->with([
        'INVALID_TRANSACTION: no such val_id (yet)' => [['status' => 'INVALID_TRANSACTION']],
        'PENDING' => [['status' => 'PENDING', 'tran_id' => SSLC_REFERENCE]],
        'a status this build does not know' => [['status' => 'PROCESSING', 'tran_id' => SSLC_REFERENCE]],
        'an empty status' => [['status' => '', 'tran_id' => SSLC_REFERENCE]],
        'no status' => [['tran_id' => SSLC_REFERENCE, 'amount' => '1500.00']],
        'an empty answer' => [[]],
        'an answer that is not JSON' => ['<html>Service Unavailable</html>'],
    ]);

    it('reports a transaction that did not go through', function (string $status, PaymentStatus $expected) {
        ($this->fakeValidation)(($this->transaction)(['status' => $status, 'error' => 'Transaction declined by the issuer']));

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']);

        expect($verified->status)->toBe($expected)
            ->and($verified->failureReason)->toBe('Transaction declined by the issuer')
            ->and($verified->amount)->toBeNull();
    })->with([
        'FAILED' => ['FAILED', PaymentStatus::Failed],
        'failed, in lower case' => ['failed', PaymentStatus::Failed],
        'EXPIRED' => ['EXPIRED', PaymentStatus::Failed],
        'UNATTEMPTED' => ['UNATTEMPTED', PaymentStatus::Failed],
        'CANCELLED' => ['CANCELLED', PaymentStatus::Canceled],
    ]);

    it('gives no reason when SSLCommerz gives none', function () {
        ($this->fakeValidation)(['status' => 'FAILED', 'tran_id' => SSLC_REFERENCE, 'error' => '']);

        expect(($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc'])->failureReason)->toBeNull();
    });

    it('drops a valid transaction that is not this payment and asks about its own reference', function (array $overrides) {
        ($this->fakeValidation)(($this->transaction)($overrides));
        ($this->fakeQuery)(($this->attempts)());

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'val-of-someone-else']);

        // Not paid on someone else's transaction - and not failed on it either.
        expect($verified->status)->toBe(PaymentStatus::Pending)
            ->and($verified->failureReason)->toBeNull()
            // Nothing of the other transaction is taken over.
            ->and($verified->amount)->toBeNull()
            ->and($verified->gatewayReference)->toBeNull()
            ->and($verified->details)->toBe([]);

        $asked = Http::recorded()->map(fn (array $exchange): Request => $exchange[0])->values();

        expect($asked)->toHaveCount(2)
            ->and($asked[1]->url())->toContain('/validator/api/merchantTransIDvalidationAPI.php')
            ->and($asked[1]->data()['tran_id'])->toBe(SSLC_REFERENCE);
    })->with([
        'another reference' => [['tran_id' => '01JNZ8Q6X3V9T4M2K7H5C1B0AF']],
        'our reference in another case' => [['tran_id' => strtolower(SSLC_REFERENCE)]],
        'our reference with something appended' => [['tran_id' => SSLC_REFERENCE.'1']],
        'no reference' => [['tran_id' => null]],
        'an empty reference' => [['tran_id' => '']],
    ]);

    it('cannot be made to fail or pass a payment with the val_id of another transaction', function (string $status) {
        // What someone who knows our reference could send: the val_id of a transaction of their own.
        ($this->fakeValidation)(($this->transaction)(['status' => $status, 'tran_id' => '01JNZ8Q6X3V9T4M2K7H5C1B0AF', 'error' => 'Declined']));
        ($this->fakeQuery)(($this->attempts)(($this->transaction)()));

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'val-of-someone-else']);

        // What SSLCommerz says about our own reference is what counts.
        expect($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->amount)->toBe(150000)
            ->and($verified->currency)->toBe('BDT');
    })->with(['FAILED', 'CANCELLED', 'VALID']);

    it('holds a transaction SSLCommerz marks as risky, with a reason, instead of accepting it', function (mixed $level) {
        ($this->fakeValidation)(($this->transaction)(['risk_level' => $level, 'risk_title' => 'Not Safe']));

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']);

        expect($verified->status)->toBe(PaymentStatus::Pending)
            ->and($verified->failureReason)->toBe('Held by the gateway for a risk review.')
            // What was charged is known, for the operator who decides.
            ->and($verified->amount)->toBe(150000)
            ->and($verified->currency)->toBe('BDT')
            ->and($verified->details['risk_level'])->toBe('1')
            ->and($verified->details['risk_title'])->toBe('Not Safe')
            ->and($verified->details['val_id'])->toBe('2603101504210kVvTgEXyq4EEPE');
    })->with(['as a string' => ['1'], 'as a number' => [1]]);

    it('accepts a transaction without a risk level', function () {
        $transaction = ($this->transaction)();
        unset($transaction['risk_level'], $transaction['risk_title']);

        ($this->fakeValidation)($transaction);

        expect(($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc'])->status)->toBe(PaymentStatus::Succeeded);
    });

    it('fails, to be asked again later, on an HTTP error or when SSLCommerz cannot be reached', function () {
        ($this->fakeValidation)(($this->transaction)(), 503);

        expect(($this->failure)(fn () => ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']))->getMessage())
            ->toBe('SSLCommerz answered with HTTP 503.');

        Http::swap(new Factory);
        Http::preventStrayRequests();
        ($this->fakeValidation)(Http::failedConnection());

        $exception = ($this->failure)(fn () => (new SslCommerzGateway(Http::getFacadeRoot(), SSLC_STORE_ID, SSLC_STORE_PASSWORD, true))->verify(($this->payment)(), ['val_id' => 'abc']));

        expect($exception->getMessage())->toBe('SSLCommerz could not be reached.')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
    });
});

describe('verifying without a val_id', function () {
    it('asks the transaction query API about our reference', function (array $payload) {
        ($this->fakeQuery)(($this->attempts)(($this->transaction)()));

        $verified = ($this->gateway)()->verify(($this->payment)(), $payload);
        $request = ($this->sent)();

        expect($request->method())->toBe('GET')
            ->and(strtok($request->url(), '?'))->toBe(SSLC_SANDBOX.'/validator/api/merchantTransIDvalidationAPI.php')
            ->and($request->data())->toEqual([
                'tran_id' => SSLC_REFERENCE,
                'store_id' => SSLC_STORE_ID,
                'store_passwd' => SSLC_STORE_PASSWORD,
                'format' => 'json',
            ])
            ->and($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->amount)->toBe(150000)
            ->and($verified->currency)->toBe('BDT')
            ->and($verified->gatewayReference)->toBe('260310150421IxTtHRMCPhU6m5A');
    })->with([
        'nothing arrived' => [[]],
        'a cancelled payer: a reference, no val_id' => [['tran_id' => 'SOMETHING-ELSE', 'status' => 'CANCELLED']],
        'an empty val_id' => [['val_id' => '']],
        'a val_id that is not text' => [['val_id' => ['a', 'b']]],
    ]);

    it('asks the live host when it is not in sandbox mode', function () {
        ($this->fakeQuery)(($this->attempts)(($this->transaction)()));

        ($this->gateway)(false)->verify(($this->payment)(), []);

        expect(strtok(($this->sent)()->url(), '?'))->toBe(SSLC_LIVE.'/validator/api/merchantTransIDvalidationAPI.php');
    });

    it('prefers the attempt that went through, wherever it is among several', function (array $statuses) {
        $attempts = array_map(fn (string $status, int $index): array => ($this->transaction)([
            'status' => $status,
            'bank_tran_id' => "BANK-{$index}-{$status}",
            'val_id' => "VAL-{$index}",
            'error' => $status === 'FAILED' ? 'Declined' : '',
        ]), $statuses, array_keys($statuses));

        ($this->fakeQuery)(($this->attempts)(...$attempts));

        $verified = ($this->gateway)()->verify(($this->payment)(), []);

        expect($verified->status)->toBe(PaymentStatus::Succeeded)
            ->and($verified->gatewayReference)->toEndWith('-VALID')
            ->and($verified->amount)->toBe(150000);
    })->with([
        'last' => [['FAILED', 'FAILED', 'VALID']],
        'first' => [['VALID', 'FAILED', 'CANCELLED']],
        'in the middle' => [['FAILED', 'VALID', 'UNATTEMPTED']],
    ]);

    it('lets the latest attempt speak when none went through', function (array $statuses, PaymentStatus $expected, ?string $reason) {
        $attempts = array_map(fn (string $status): array => ($this->transaction)([
            'status' => $status,
            'error' => $status === 'FAILED' ? 'Declined' : '',
        ]), $statuses);

        ($this->fakeQuery)(($this->attempts)(...$attempts));

        $verified = ($this->gateway)()->verify(($this->payment)(), []);

        expect($verified->status)->toBe($expected)
            ->and($verified->failureReason)->toBe($reason)
            ->and($verified->amount)->toBeNull();
    })->with([
        'failed, then cancelled' => [['FAILED', 'CANCELLED'], PaymentStatus::Canceled, null],
        'cancelled, then failed' => [['CANCELLED', 'FAILED'], PaymentStatus::Failed, 'Declined'],
        'failed, then still in progress' => [['FAILED', 'PENDING'], PaymentStatus::Pending, null],
        'one failed attempt' => [['FAILED'], PaymentStatus::Failed, 'Declined'],
    ]);

    it('decides nothing when no attempt has been made yet', function (array $answer) {
        ($this->fakeQuery)($answer);

        expect(($this->gateway)()->verify(($this->payment)(), [])->status)->toBe(PaymentStatus::Pending);
    })->with([
        'an empty list' => [['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]],
        'no list' => [['APIConnect' => 'DONE', 'no_of_trans_found' => 0]],
        'a list of something else' => [['APIConnect' => 'DONE', 'element' => ['', null, 7]]],
    ]);

    it('holds a risky attempt and rejects one about another reference, as with a val_id', function () {
        ($this->fakeQuery)(($this->attempts)(($this->transaction)(['risk_level' => '1'])));

        expect(($this->gateway)()->verify(($this->payment)(), [])->status)->toBe(PaymentStatus::Pending);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        ($this->fakeQuery)(($this->attempts)(($this->transaction)(['tran_id' => 'SOMETHING-ELSE'])));

        $verified = (new SslCommerzGateway(Http::getFacadeRoot(), SSLC_STORE_ID, SSLC_STORE_PASSWORD, true))->verify(($this->payment)(), []);

        expect($verified->status)->toBe(PaymentStatus::Failed)
            ->and($verified->failureReason)->toBe('The gateway answered about a different transaction.');
    });

    it('fails when SSLCommerz does not accept the query', function (array $answer, string $message) {
        ($this->fakeQuery)($answer);

        expect(($this->failure)(fn () => ($this->gateway)()->verify(($this->payment)(), []))->getMessage())->toBe($message);
    })->with([
        'INVALID_REQUEST' => [['APIConnect' => 'INVALID_REQUEST'], 'SSLCommerz did not accept the transaction query: INVALID_REQUEST'],
        'FAILED' => [['APIConnect' => 'FAILED'], 'SSLCommerz did not accept the transaction query: FAILED'],
        'INACTIVE' => [['APIConnect' => 'INACTIVE'], 'SSLCommerz did not accept the transaction query: INACTIVE'],
        'done, in lower case, is not DONE' => [['APIConnect' => 'done', 'element' => []], 'SSLCommerz did not accept the transaction query: done'],
        'no APIConnect, even with a valid-looking attempt' => [['element' => [['status' => 'VALID', 'tran_id' => SSLC_REFERENCE]]], 'SSLCommerz did not accept the transaction query: no answer'],
        'an empty answer' => [[], 'SSLCommerz did not accept the transaction query: no answer'],
    ]);

    it('fails on an HTTP error', function () {
        ($this->fakeQuery)(($this->attempts)(($this->transaction)()), 500);

        expect(($this->failure)(fn () => ($this->gateway)()->verify(($this->payment)(), []))->getMessage())
            ->toBe('SSLCommerz answered with HTTP 500.');
    });

    it('fails when SSLCommerz cannot be reached', function () {
        ($this->fakeQuery)(Http::failedConnection());

        expect(($this->failure)(fn () => ($this->gateway)()->verify(($this->payment)(), []))->getMessage())
            ->toBe('SSLCommerz could not be reached.');
    });
});

describe('the reference in a notification', function () {
    it('is the tran_id, when it is text', function (array $payload, ?string $expected) {
        Http::fake();

        expect(($this->gateway)()->referenceFrom($payload))->toBe($expected);

        // Reading a notification asks nobody anything.
        Http::assertNothingSent();
    })->with([
        'an IPN' => [['tran_id' => SSLC_REFERENCE, 'val_id' => 'abc', 'status' => 'VALID', 'amount' => '1500.00'], SSLC_REFERENCE],
        'no tran_id' => [['val_id' => 'abc'], null],
        'an empty tran_id' => [['tran_id' => ''], null],
        'a tran_id that is a list' => [['tran_id' => [SSLC_REFERENCE]], null],
        'a tran_id that is a number' => [['tran_id' => 42], null],
        'nothing' => [[], null],
    ]);
});

describe('credentials', function () {
    it('never appear in an error or in what is kept about a transaction', function () {
        // A transaction that repeats the credentials, as a careless or hostile answer might.
        $leaky = ($this->transaction)(['store_id' => SSLC_STORE_ID, 'store_passwd' => SSLC_STORE_PASSWORD, 'verify_key' => SSLC_STORE_PASSWORD]);

        $run = function (array $fakes, Closure $call): mixed {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake($fakes);

            $gateway = new SslCommerzGateway(Http::getFacadeRoot(), SSLC_STORE_ID, SSLC_STORE_PASSWORD, true);

            try {
                return $call($gateway);
            } catch (GatewayException $exception) {
                return $exception;
            }
        };

        $outcomes = [
            'session refused' => $run(
                ['*/gwprocess/v4/api.php' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active'])],
                fn (SslCommerzGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)()),
            ),
            'session HTTP error' => $run(
                ['*/gwprocess/v4/api.php' => Http::response('Forbidden', 403)],
                fn (SslCommerzGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)()),
            ),
            'session unreachable' => $run(
                ['*/gwprocess/v4/api.php' => Http::failedConnection()],
                fn (SslCommerzGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)()),
            ),
            'missing details' => $run(
                [],
                fn (SslCommerzGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)(['city' => null])),
            ),
            'session opened' => $run(
                ['*/gwprocess/v4/api.php' => Http::response(($this->session)(['store_id' => SSLC_STORE_ID, 'store_passwd' => SSLC_STORE_PASSWORD]))],
                fn (SslCommerzGateway $gateway) => $gateway->initiate(($this->payment)(), ($this->request)()),
            ),
            'validation HTTP error' => $run(
                ['*/validator/api/validationserverAPI.php*' => Http::response('Bad gateway', 502)],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), ['val_id' => 'abc']),
            ),
            'query refused' => $run(
                ['*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => 'INVALID_REQUEST'])],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), []),
            ),
            'query HTTP error' => $run(
                ['*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response('Bad gateway', 502)],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), []),
            ),
            'valid' => $run(
                ['*/validator/api/validationserverAPI.php*' => Http::response($leaky)],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), ['val_id' => 'abc']),
            ),
            'valid, by reference' => $run(
                ['*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(($this->attempts)($leaky))],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), []),
            ),
            'held' => $run(
                ['*/validator/api/validationserverAPI.php*' => Http::response([...$leaky, 'risk_level' => '1'])],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), ['val_id' => 'abc']),
            ),
            'failed' => $run(
                ['*/validator/api/validationserverAPI.php*' => Http::response([...$leaky, 'status' => 'FAILED', 'error' => 'Declined'])],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), ['val_id' => 'abc']),
            ),
            'another transaction' => $run(
                [
                    '*/validator/api/validationserverAPI.php*' => Http::response([...$leaky, 'tran_id' => 'SOMETHING-ELSE']),
                    '*/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(($this->attempts)()),
                ],
                fn (SslCommerzGateway $gateway) => $gateway->verify(($this->payment)(), ['val_id' => 'abc']),
            ),
        ];

        foreach ($outcomes as $case => $outcome) {
            $kept = match (true) {
                $outcome instanceof GatewayException => $outcome->getMessage(),
                $outcome instanceof PaymentRedirect => json_encode([$outcome->url, $outcome->details]),
                $outcome instanceof VerifiedPayment => json_encode(get_object_vars($outcome)),
            };

            expect($kept)->not->toContain(SSLC_STORE_ID, SSLC_STORE_PASSWORD, urlencode(SSLC_STORE_PASSWORD), rawurlencode(SSLC_STORE_PASSWORD));
        }

        expect(array_map(fn ($outcome): string => $outcome::class, $outcomes))->toBe([
            'session refused' => GatewayException::class,
            'session HTTP error' => GatewayException::class,
            'session unreachable' => GatewayException::class,
            'missing details' => GatewayException::class,
            'session opened' => PaymentRedirect::class,
            'validation HTTP error' => GatewayException::class,
            'query refused' => GatewayException::class,
            'query HTTP error' => GatewayException::class,
            'valid' => VerifiedPayment::class,
            'valid, by reference' => VerifiedPayment::class,
            'held' => VerifiedPayment::class,
            'failed' => VerifiedPayment::class,
            'another transaction' => VerifiedPayment::class,
        ]);
    });

    it('keeps no card number, only how the payment was made', function () {
        ($this->fakeValidation)(($this->transaction)());

        $verified = ($this->gateway)()->verify(($this->payment)(), ['val_id' => 'abc']);

        expect(json_encode(get_object_vars($verified)))->not->toContain('432155', '3964', 'STANDARD CHARTERED');
    });
});

/*
 * The gateway behind PaymentProcessor, the way a request reaches it: taken
 * from the registry, which builds it from config/platform.php.
 */
describe('through the payment processor', function () {
    beforeEach(function () {
        Mail::fake();

        $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

        $this->acme = $this->createTenant('acme', ['name' => 'Acme']);
        $this->subscribe($this->acme, $this->createPlan(['monthly_amount' => 150000]), withTrial: false);
        $this->invoice = $this->openInvoiceOf($this->acme);

        $this->configure = function (?string $storeId = SSLC_STORE_ID, ?string $storePassword = SSLC_STORE_PASSWORD): void {
            config(['platform.gateways.sslcommerz' => ['store_id' => $storeId, 'store_password' => $storePassword, 'sandbox' => true]]);

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
            fn (): PaymentRedirect => app(PaymentProcessor::class)->begin($this->invoice, 'sslcommerz', $this->ownerOf($this->acme)),
        );

        $this->refusal = function (): array {
            try {
                ($this->begin)();
            } catch (ValidationException $exception) {
                return array_map(static fn (array $messages): string => $messages[0], $exception->errors());
            }

            $this->fail('The payment was not refused.');
        };
    });

    it('is refused, and asks nobody anything, while it has no credentials', function (?string $storeId, ?string $storePassword) {
        Http::fake();

        ($this->configure)($storeId, $storePassword);
        $this->acme->update($this->profile);

        expect(($this->refusal)())->toBe(['gateway' => 'This payment method is not available.'])
            ->and($this->paymentsOf($this->acme))->toBeEmpty();

        Http::assertNothingSent();
    })->with([
        'no credentials' => [null, null],
        'a store id only' => [SSLC_STORE_ID, null],
        'empty credentials' => ['', ''],
    ]);

    it('opens the payment for the invoice and settles it on the validation answer', function () {
        ($this->configure)();
        $this->acme->update($this->profile);

        ($this->fakeSession)(($this->session)());

        $redirect = ($this->begin)();
        $payment = $this->paymentsOf($this->acme)->sole();
        $posted = ($this->sent)()->data();

        expect($redirect->url)->toBe(SSLC_SANDBOX.'/EasyCheckOut/testcdea8ef93b75b8107e4f36049e80b4f9149')
            ->and($payment->gateway)->toBe('sslcommerz')
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->details)->toEqual(['sessionkey' => 'A8EF93B75B8107E4F36049E80B4F9149'])
            ->and($posted['tran_id'])->toBe($payment->reference)
            ->and($posted['total_amount'])->toBe('1500.00')
            ->and($posted['currency'])->toBe('BDT')
            ->and($posted['product_name'])->toBe('Invoice '.$this->invoice->number)
            ->and($posted['cus_name'])->toBe('Acme Trading Limited')
            ->and($posted['success_url'])->toBe("http://acme.localhost/billing/payments/{$payment->reference}/return")
            ->and($posted['ipn_url'])->toBe('http://acme.localhost/billing/gateways/sslcommerz/notify')
            // Neither in the row ...
            ->and(json_encode($payment->getAttributes()))->not->toContain(SSLC_STORE_ID, SSLC_STORE_PASSWORD)
            // ... nor in the workspace's audit trail.
            ->and(json_encode($this->auditTrail($this->acme)))->not->toContain(SSLC_STORE_ID, SSLC_STORE_PASSWORD);

        ($this->fakeValidation)(($this->transaction)(['tran_id' => $payment->reference]));

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment, ['val_id' => '2603101504210kVvTgEXyq4EEPE']));

        expect($confirmed->status)->toBe(PaymentStatus::Succeeded)
            ->and($confirmed->gateway_reference)->toBe('260310150421IxTtHRMCPhU6m5A')
            ->and($confirmed->method)->toBe('VISA-Dutch Bangla')
            ->and($confirmed->refresh()->details)->toHaveKeys(['val_id', 'bank_tran_id', 'card_type', 'store_amount'])
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and(json_encode($confirmed->getAttributes()))->not->toContain(SSLC_STORE_ID, SSLC_STORE_PASSWORD)
            ->and(json_encode($this->auditTrail($this->acme)))->not->toContain(SSLC_STORE_ID, SSLC_STORE_PASSWORD);
    });

    it('fails the payment when SSLCommerz validates another amount than the invoice', function () {
        ($this->configure)();
        $this->acme->update($this->profile);

        ($this->fakeSession)(($this->session)());
        ($this->begin)();

        $payment = $this->paymentsOf($this->acme)->sole();

        ($this->fakeValidation)(($this->transaction)(['tran_id' => $payment->reference, 'amount' => '15.00', 'currency_amount' => '15.00']));

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment, ['val_id' => 'abc']));

        expect($confirmed->status)->toBe(PaymentStatus::Failed)
            ->and($confirmed->failure_reason)->toBe('The gateway confirmed a different amount or currency than the invoice.')
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
    });

    it('says which company details are missing, and keeps the attempt as failed', function (string $missing) {
        Http::fake();

        ($this->configure)();
        $this->acme->update([...$this->profile, $missing => null]);

        expect(($this->refusal)())->toBe([
            'gateway' => 'Add the company phone number, address, city, postal code and country in the workspace settings before paying online.',
        ]);

        $payment = $this->paymentsOf($this->acme)->sole();

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_reason)->toContain('phone number, address, city, postal code and country')
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);

        Http::assertNothingSent();
    })->with(['phone', 'address', 'city', 'postal_code', 'country']);

    it('gives a general message when SSLCommerz refuses the session, and keeps its reason for the operator', function () {
        ($this->configure)();
        $this->acme->update($this->profile);

        ($this->fakeSession)(['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active']);

        expect(($this->refusal)())->toBe(['gateway' => 'The payment could not be started. Please try again, or choose another payment method.']);

        $payment = $this->paymentsOf($this->acme)->sole();

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_reason)->toBe('SSLCommerz refused the payment session: Store Credential Error Or Store is De-active');
    });

    it('leaves the payment pending when SSLCommerz cannot be reached to verify it', function () {
        ($this->configure)();
        $this->acme->update($this->profile);

        ($this->fakeSession)(($this->session)());
        ($this->begin)();

        $payment = $this->paymentsOf($this->acme)->sole();

        ($this->fakeValidation)(Http::failedConnection());

        $confirmed = $this->inTenant($this->acme, fn (): Payment => app(PaymentProcessor::class)->confirm($payment, ['val_id' => 'abc']));

        expect($confirmed->status)->toBe(PaymentStatus::Pending)
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
    });
});
