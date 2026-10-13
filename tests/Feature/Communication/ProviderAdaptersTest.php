<?php

declare(strict_types=1);

use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Providers\HttpSmsProvider;
use App\Modules\Communication\Channels\Providers\SmtpProvider;
use App\Modules\Communication\Channels\Providers\TwilioSmsProvider;
use App\Modules\Communication\Channels\Providers\WhatsAppCloudProvider;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithProviderAccounts;

/*
 * The provider adapters of ADR 0013 against a faked HTTP layer. Nothing here talks to a live provider: these
 * tests prove the request the adapter builds and how it classifies the answer, not that Twilio, a gateway or Meta
 * accept it.
 */

uses(InteractsWithProviderAccounts::class);

/** The query string of a recorded request as an array. @return array<string, string> */
function recordedQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

describe('Twilio SMS', function () {
    beforeEach(function () {
        $this->twilio = new TwilioSmsProvider;
        $this->send = fn (?string $to = null, array $settings = [], string $body = 'Hello there', ?string $secret = self::TWILIO_TOKEN) => $this->twilio->send(
            $this->smsPayload($to ?? '+8801711000000', $body),
            $this->smsConnection('twilio'),
            $secret === null ? null : $this->credentials($secret, [
                'account_sid' => self::TWILIO_SID, 'from' => '+15005550006', ...$settings,
            ]),
        );
    });

    it('posts the message with basic auth and returns the message sid', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM42', 'status' => 'queued'], 201)]);

        $result = ($this->send)();

        expect($result->outcome)->toBe(SendResult::ACCEPTED)->and($result->providerMessageId)->toBe('SM42');
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.self::TWILIO_SID.'/Messages.json'
                && $request->header('Authorization')[0] === 'Basic '.base64_encode(self::TWILIO_SID.':'.self::TWILIO_TOKEN)
                && $request['To'] === '+8801711000000'
                && $request['From'] === '+15005550006'
                && $request['Body'] === 'Hello there'
                && ! isset($request['MessagingServiceSid']);
        });
    });

    it('uses the messaging service sid when the sender is one', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM43'], 201)]);

        ($this->send)(null, ['from' => 'MG0123456789abcdef0123456789abcdef']);

        Http::assertSent(fn (Request $request): bool => $request['MessagingServiceSid'] === 'MG0123456789abcdef0123456789abcdef'
            && ! isset($request['From']));
    });

    it('truncates a very long message to what Twilio accepts', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM44'], 201)]);

        ($this->send)(null, [], str_repeat('a', 3000));

        Http::assertSent(fn (Request $request): bool => mb_strlen($request['Body']) === 1600);
    });

    it('follows the configured base url', function () {
        config(['communication.sms.twilio_base_url' => 'https://twilio-proxy.example.com/']);
        Http::fake(['twilio-proxy.example.com/*' => Http::response(['sid' => 'SM45'], 201)]);

        expect(($this->send)()->outcome)->toBe(SendResult::ACCEPTED);
    });

    it('classifies provider answers', function (int $status, string $outcome, string $code) {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'The token '.self::TWILIO_TOKEN.' is wrong'], $status)]);

        $result = ($this->send)();

        expect($result->outcome)->toBe($outcome)->and($result->code)->toBe($code)
            ->and((string) $result->reason)->not->toContain(self::TWILIO_TOKEN)->not->toContain('is wrong');
    })->with([
        '401' => [401, SendResult::REJECTED, 'provider_credentials_rejected'],
        '403' => [403, SendResult::REJECTED, 'provider_credentials_rejected'],
        '429' => [429, SendResult::RETRY, 'provider_unavailable'],
        '500' => [500, SendResult::RETRY, 'provider_unavailable'],
        '503' => [503, SendResult::RETRY, 'provider_unavailable'],
        '400' => [400, SendResult::REJECTED, 'provider_rejected'],
    ]);

    it('names the provider error code but never its text on a 400', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'Invalid To number'], 400)]);

        expect(($this->send)()->reason)->toContain('21211')->not->toContain('Invalid To number');
    });

    it('retries when Twilio is unreachable and exposes only a class name for other failures', function () {
        $failure = new ConnectionException('cURL error 6: could not resolve host api.twilio.com for '.self::TWILIO_TOKEN);
        Http::fake(['api.twilio.com/*' => function () use (&$failure) {
            throw $failure;
        }]);
        $unreachable = ($this->send)();

        expect($unreachable->outcome)->toBe(SendResult::RETRY)
            ->and($unreachable->code)->toBe('provider_unreachable')
            ->and((string) $unreachable->reason)->not->toContain(self::TWILIO_TOKEN);

        $failure = new RuntimeException('boom '.self::TWILIO_TOKEN);
        $other = ($this->send)();

        expect($other->outcome)->toBe(SendResult::RETRY)
            ->and($other->code)->toBe('provider_error')
            ->and($other->reason)->toBe('RuntimeException');
    });

    it('retries a success answer that carries no message id', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['status' => 'queued'], 201)]);

        expect(($this->send)()->code)->toBe('provider_malformed_response');
    });

    it('rejects locally what it can know is wrong, without calling Twilio', function () {
        Http::fake();

        expect(($this->send)('8801711000000')->code)->toBe('invalid_recipient')
            ->and(($this->send)('+0123')->code)->toBe('invalid_recipient')
            ->and(($this->send)(null, [], '   ')->code)->toBe('empty_message')
            ->and(($this->send)(null, [], 'x', null)->code)->toBe('provider_not_configured')
            ->and(($this->send)(null, ['account_sid' => 'not-a-sid'])->code)->toBe('provider_not_configured')
            ->and(($this->send)(null, ['from' => ''])->code)->toBe('provider_not_configured');
        Http::assertNothingSent();
    });
});

describe('HTTP SMS gateway', function () {
    beforeEach(function () {
        $this->gateway = new HttpSmsProvider;
        $this->key = 'gateway-api-key-SECRET-2';
        $this->send = fn (array $settings = [], string $to = '+8801711000000', string $body = 'Hello there', ?string $key = 'gateway-api-key-SECRET-2') => $this->gateway->send(
            $this->smsPayload($to, $body),
            $this->smsConnection('http_sms'),
            $key === null ? null : $this->credentials($key, [...$this->httpSmsSettings(), ...$settings]),
        );
    });

    it('sends the key as a bearer token with a form body by default', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::response(['message_id' => 'G1'], 200)]);

        $result = ($this->send)();

        expect($result->outcome)->toBe(SendResult::ACCEPTED)->and($result->providerMessageId)->toBe('G1');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sms.gateway-example.com/api/send'
            && $request->header('Authorization')[0] === 'Bearer '.$this->key
            && $request->isForm()
            && $request['to'] === '+8801711000000'
            && $request['text'] === 'Hello there');
    });

    it('sends the key in each configured way', function (array $settings, Closure $assert) {
        Http::fake(['sms.gateway-example.com/*' => Http::response('OK', 200)]);

        expect(($this->send)($settings)->outcome)->toBe(SendResult::ACCEPTED);

        Http::assertSentCount(1);
        Http::assertSent($assert);
    })->with([
        'header' => [
            ['auth_mode' => 'header', 'auth_name' => 'X-Api-Key'],
            fn (Request $request): bool => $request->header('X-Api-Key')[0] === 'gateway-api-key-SECRET-2' && ! $request->hasHeader('Authorization'),
        ],
        'query' => [
            ['auth_mode' => 'query', 'auth_name' => 'api_key'],
            fn (Request $request): bool => recordedQuery($request)['api_key'] === 'gateway-api-key-SECRET-2'
                && $request['to'] === '+8801711000000' && ! isset($request['api_key']),
        ],
        'body' => [
            ['auth_mode' => 'body', 'auth_name' => 'apikey'],
            fn (Request $request): bool => $request['apikey'] === 'gateway-api-key-SECRET-2' && ! $request->hasHeader('Authorization')
                && ! str_contains($request->url(), 'gateway-api-key-SECRET-2'),
        ],
        'basic' => [
            ['auth_mode' => 'basic', 'auth_name' => 'oyosend'],
            fn (Request $request): bool => $request->header('Authorization')[0] === 'Basic '.base64_encode('oyosend:gateway-api-key-SECRET-2'),
        ],
    ]);

    it('can send as GET with every parameter in the query string', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::response('OK', 200)]);

        ($this->send)(['method' => 'GET', 'auth_mode' => 'query', 'auth_name' => 'key']);

        Http::assertSent(function (Request $request): bool {
            $query = recordedQuery($request);

            return $request->method() === 'GET'
                && $query['to'] === '+8801711000000' && $query['text'] === 'Hello there' && $query['key'] === 'gateway-api-key-SECRET-2';
        });
    });

    it('can send a POST with the parameters in the query string', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::response('OK', 200)]);

        ($this->send)(['method' => 'POST', 'body_format' => 'query']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && recordedQuery($request)['to'] === '+8801711000000');
    });

    it('can send a JSON body', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::response('OK', 200)]);

        ($this->send)(['body_format' => 'json']);

        Http::assertSent(fn (Request $request): bool => $request->isJson()
            && $request->data() === ['to' => '+8801711000000', 'text' => 'Hello there']);
    });

    it('formats the number as digits, adds the sender and the fixed parameters', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::response('OK', 200)]);

        ($this->send)([
            'to_format' => 'digits', 'sender_param' => 'senderid', 'sender_value' => 'ACME', 'extra_params' => 'type=text&unicode=1',
        ]);

        Http::assertSent(fn (Request $request): bool => $request['to'] === '8801711000000'
            && $request['senderid'] === 'ACME' && $request['type'] === 'text' && $request['unicode'] === '1');
    });

    it('requires the success text in the answer when one is configured', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::sequence()
            ->push('{"status":"ERROR","detail":"insufficient balance"}', 200)
            ->push('SUCCESS 1001', 200),
        ]);

        $failed = ($this->send)(['success_contains' => 'SUCCESS']);
        $accepted = ($this->send)(['success_contains' => 'SUCCESS']);

        expect($failed->outcome)->toBe(SendResult::REJECTED)
            ->and($failed->code)->toBe('provider_rejected')
            ->and((string) $failed->reason)->not->toContain('insufficient balance')
            ->and($accepted->outcome)->toBe(SendResult::ACCEPTED);
    });

    it('classifies provider answers and never puts the key, the endpoint or the body into a result', function (int $status, string $outcome, string $code) {
        Http::fake(['sms.gateway-example.com/*' => Http::response('denied for key '.$this->key, $status)]);

        foreach ([['auth_mode' => 'bearer'], ['auth_mode' => 'query', 'auth_name' => 'api_key']] as $settings) {
            $result = ($this->send)($settings);

            expect($result->outcome)->toBe($outcome)->and($result->code)->toBe($code)
                ->and(json_encode([$result->code, $result->reason, $result->providerMessageId]))
                ->not->toContain($this->key)->not->toContain('sms.gateway-example.com')->not->toContain('denied for key');
        }
    })->with([
        '401' => [401, SendResult::REJECTED, 'provider_credentials_rejected'],
        '403' => [403, SendResult::REJECTED, 'provider_credentials_rejected'],
        '429' => [429, SendResult::RETRY, 'provider_unavailable'],
        '502' => [502, SendResult::RETRY, 'provider_unavailable'],
        '422' => [422, SendResult::REJECTED, 'provider_rejected'],
    ]);

    it('does not follow a redirect to another host', function () {
        Http::fake([
            'sms.gateway-example.com/*' => Http::response('', 302, ['Location' => 'https://evil.example.org/collect']),
            'evil.example.org/*' => Http::response('stolen', 200),
        ]);

        $result = ($this->send)();

        expect($result->outcome)->toBe(SendResult::REJECTED)->and($result->code)->toBe('provider_rejected');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example.org'));
        Http::assertSentCount(1);
    });

    it('retries when the gateway cannot be reached, without exposing the error text', function () {
        Http::fake(['sms.gateway-example.com/*' => fn () => throw new ConnectionException('timeout talking to sms.gateway-example.com with '.$this->key)]);

        $result = ($this->send)(['auth_mode' => 'query', 'auth_name' => 'api_key']);

        expect($result->outcome)->toBe(SendResult::RETRY)
            ->and($result->code)->toBe('provider_unreachable')
            ->and(json_encode([$result->code, $result->reason]))->not->toContain($this->key)->not->toContain('gateway-example');
    });

    it('rejects locally without calling the gateway when the account or recipient is not usable', function () {
        Http::fake();

        expect(($this->send)([], '+8801711000000', 'x', null)->code)->toBe('provider_not_configured')
            // A stored account that would not pass today's endpoint rules is never called.
            ->and(($this->send)(['endpoint' => 'http://sms.gateway-example.com/send'])->code)->toBe('provider_not_configured')
            ->and(($this->send)(['endpoint' => 'https://10.0.0.5/send'])->code)->toBe('provider_not_configured')
            ->and(($this->send)([], '01711000000')->code)->toBe('invalid_recipient')
            ->and(($this->send)([], '+8801711000000', '  ')->code)->toBe('empty_message');
        Http::assertNothingSent();
    });

    it('returns no message id when the gateway gives none', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::response('OK', 200)]);

        $result = ($this->send)();

        expect($result->outcome)->toBe(SendResult::ACCEPTED)->and($result->providerMessageId)->toBeNull();
    });
});

describe('SMTP', function () {
    beforeEach(function () {
        $this->smtp = new SmtpProvider;
        $this->payload = fn () => new OutboundPayload(
            '01TESTMESSAGE', Channel::Email, 'person@example.test', 'Subject', 'Body', 'en',
        );
        $this->connection = new ChannelConnection(['channel' => Channel::Email, 'provider' => 'smtp', 'is_enabled' => true]);
    });

    it('is not configured without an account or without a host and sender', function (?array $settings) {
        $credentials = $settings === null ? null : $this->credentials('pw', $settings);

        $result = $this->smtp->send(($this->payload)(), $this->connection, $credentials);

        expect($result->outcome)->toBe(SendResult::REJECTED)->and($result->code)->toBe('provider_not_configured');
    })->with([
        'no credentials' => [null],
        'no host' => [['from_address' => 'a@example.com']],
        'no sender' => [['host' => 'smtp.mail-example.com']],
        'empty host' => [['host' => '', 'from_address' => 'a@example.com']],
    ]);

    it('retries a transport failure and reports only the exception class', function () {
        // Nothing listens on port 1 of the loopback interface: the connection is refused at once.
        $result = $this->smtp->send(($this->payload)(), $this->connection, $this->credentials('smtp-password-SECRET-3', [
            'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'starttls', 'username' => 'mailer-user-SECRET', 'from_address' => 'a@example.com',
        ]));

        expect($result->outcome)->toBe(SendResult::RETRY)
            ->and($result->code)->toBe('smtp_transport_error')
            ->and($result->reason)->toMatch('/^[A-Za-z]+$/')
            ->and(json_encode([$result->code, $result->reason]))->not->toContain('127.0.0.1')->not->toContain('SECRET');
    });
});

describe('WhatsApp Cloud account credentials', function () {
    it('sends with the account access token and the workspace phone number id', function () {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);
        $connection = new ChannelConnection([
            'channel' => Channel::Whatsapp, 'provider' => 'whatsapp_cloud', 'is_enabled' => true,
            'settings' => ['phone_number_id' => '109876543210'],
        ]);
        $connection->credential_ref = 'acct_01hzzzzzzzzzzzzzzzzzzzzzzz';

        $result = (new WhatsAppCloudProvider)->send(
            new OutboundPayload('01M', Channel::Whatsapp, '+8801711000000', null, 'Hello', 'en'),
            $connection,
            $this->credentials('wa-access-token-SECRET-4'),
        );

        expect($result->outcome)->toBe(SendResult::ACCEPTED)->and($result->providerMessageId)->toBe('wamid.ABC');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/109876543210/messages')
            && $request->header('Authorization')[0] === 'Bearer wa-access-token-SECRET-4'
            && $request['to'] === '8801711000000');
    });

    it('is not configured without a phone number id, whatever the account holds', function () {
        Http::fake();
        $connection = new ChannelConnection(['channel' => Channel::Whatsapp, 'provider' => 'whatsapp_cloud', 'is_enabled' => true]);
        $connection->credential_ref = 'acct_01hzzzzzzzzzzzzzzzzzzzzzzz';

        $result = (new WhatsAppCloudProvider)->send(
            new OutboundPayload('01M', Channel::Whatsapp, '+8801711000000', null, 'Hello', 'en'),
            $connection,
            $this->credentials('wa-access-token-SECRET-4'),
        );

        expect($result->code)->toBe('provider_not_configured');
        Http::assertNothingSent();
    });
});
