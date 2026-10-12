<?php

declare(strict_types=1);

use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Channels\ProviderRegistry;
use App\Modules\Communication\Channels\Providers\WhatsAppCloudProvider;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Enums\ProviderEventType;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The WhatsApp Cloud API adapter, with every HTTP call faked. Nothing here reaches Meta: this suite does not
 * prove the adapter against the live platform (docs/WHATSAPP.md).
 */

function waConnection(array $settings = ['phone_number_id' => '109876543210'], ?string $ref = 'wa_token'): ChannelConnection
{
    return new ChannelConnection(['channel' => Channel::Whatsapp, 'provider' => 'whatsapp_cloud', 'is_enabled' => true, 'settings' => $settings, 'credential_ref' => $ref]);
}

function waPayload(array $meta = [], string $body = 'Hello', string $to = '+8801711000001'): OutboundPayload
{
    return new OutboundPayload('msg-1', Channel::Whatsapp, $to, null, $body, 'en', meta: $meta);
}

/** @return array{0: string, 1: array<string, string>} */
function waSigned(array $payload, string $secret = 'app-secret'): array
{
    $body = (string) json_encode($payload);

    return [$body, ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)]];
}

beforeEach(function () {
    $this->provider = new WhatsAppCloudProvider;
    $this->token = new ChannelCredentials('EAAsecret-token');
});

it('is registered as the official provider and says it is not a test double', function () {
    $registered = app(ProviderRegistry::class)->find(Channel::Whatsapp, 'whatsapp_cloud');

    expect($registered)->toBeInstanceOf(WhatsAppCloudProvider::class)
        ->and($registered->isTestDouble())->toBeFalse()
        ->and($registered->supportsWebhooks())->toBeTrue()
        ->and(array_column($registered->fields(), 'key'))->toBe(['phone_number_id', 'business_account_id', 'graph_api_version']);
});

it('is not configured without a credential reference and a phone number id', function () {
    expect($this->provider->state(waConnection()))->toBe(ConfigurationState::Configured)
        ->and($this->provider->state(waConnection(ref: null)))->toBe(ConfigurationState::NotConfigured)
        ->and($this->provider->state(waConnection([])))->toBe(ConfigurationState::NotConfigured)
        ->and($this->provider->state(waConnection(['phone_number_id' => 'abc'])))->toBe(ConfigurationState::NotConfigured);
});

it('refuses to send without credentials and makes no request', function () {
    Http::fake();

    $result = $this->provider->send(waPayload(), waConnection(), null);

    expect($result->outcome)->toBe(SendResult::REJECTED)->and($result->code)->toBe('provider_not_configured');
    Http::assertNothingSent();
});

it('sends a template message with body parameters through the Graph API', function () {
    config(['communication.whatsapp.graph_base_url' => 'https://graph.facebook.com', 'communication.whatsapp.api_version' => 'v21.0']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);

    $result = $this->provider->send(
        waPayload(['whatsapp_template' => 'order_update', 'whatsapp_language' => 'en_US', 'whatsapp_params' => json_encode(["Rahim\nUddin", '', 'Order #42'])]),
        waConnection(),
        $this->token,
    );

    expect($result->outcome)->toBe(SendResult::ACCEPTED)->and($result->providerMessageId)->toBe('wamid.ABC');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v21.0/109876543210/messages'
        && $request->hasHeader('Authorization', 'Bearer EAAsecret-token')
        && $request['messaging_product'] === 'whatsapp'
        && $request['to'] === '8801711000001'
        && $request['type'] === 'template'
        && $request['template']['name'] === 'order_update'
        && $request['template']['components'][0]['parameters'] === [
            ['type' => 'text', 'text' => 'Rahim Uddin'],
            ['type' => 'text', 'text' => '-'],
            ['type' => 'text', 'text' => 'Order #42'],
        ]);
});

it('sends free-form text only while the window is open', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TXT']]], 200)]);

    $open = $this->provider->send(waPayload(['whatsapp_window_until' => now()->addHour()->toIso8601String()], 'Thanks'), waConnection(), $this->token);
    $closed = $this->provider->send(waPayload(['whatsapp_window_until' => now()->subMinute()->toIso8601String()], 'Late'), waConnection(), $this->token);

    expect($open->outcome)->toBe(SendResult::ACCEPTED)
        ->and($closed->outcome)->toBe(SendResult::REJECTED)
        ->and($closed->code)->toBe('outside_customer_service_window');
    Http::assertSentCount(1);
});

it('refuses an invalid template name, language or recipient locally', function () {
    Http::fake();

    foreach ([
        waPayload(['whatsapp_template' => 'Bad Name', 'whatsapp_language' => 'en_US']),
        waPayload(['whatsapp_template' => 'ok_name', 'whatsapp_language' => 'english']),
    ] as $payload) {
        expect($this->provider->send($payload, waConnection(), $this->token)->code)->toBe('invalid_template');
    }

    expect($this->provider->send(waPayload(to: '01711000001'), waConnection(), $this->token)->code)->toBe('invalid_recipient');
    Http::assertNothingSent();
});

it('maps provider errors to retry or permanent and never repeats the token', function (int $status, array $body, string $outcome, string $code) {
    Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);

    $result = $this->provider->send(waPayload(), waConnection(), $this->token);

    expect($result->outcome)->toBe($outcome)
        ->and($result->code)->toBe($code)
        ->and(json_encode($result))->not->toContain('EAAsecret-token');
})->with([
    'outside the window' => [400, ['error' => ['code' => 131047, 'message' => 'Re-engagement message']], SendResult::REJECTED, 'outside_customer_service_window'],
    'undeliverable' => [400, ['error' => ['code' => 131026, 'message' => 'Message undeliverable']], SendResult::REJECTED, 'invalid_recipient'],
    'template mismatch' => [400, ['error' => ['code' => 132000, 'message' => 'Param count']], SendResult::REJECTED, 'invalid_template'],
    'bad token' => [401, ['error' => ['code' => 190, 'message' => 'Invalid OAuth access token EAAsecret-token']], SendResult::REJECTED, 'provider_authentication_failed'],
    'throttled' => [429, ['error' => ['code' => 130429, 'message' => 'Rate limit']], SendResult::RETRY, 'provider_rate_limited'],
    'pair limit' => [400, ['error' => ['code' => 131056, 'message' => 'Pair rate limit']], SendResult::RETRY, 'provider_rate_limited'],
    'server error' => [503, [], SendResult::RETRY, 'provider_unavailable'],
    'something else' => [400, ['error' => ['code' => 999, 'message' => 'Nope']], SendResult::REJECTED, 'provider_rejected'],
]);

it('retries on a network failure', function () {
    Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timeout')]);

    $result = $this->provider->send(waPayload(), waConnection(), $this->token);

    expect($result->outcome)->toBe(SendResult::RETRY)->and($result->code)->toBe('provider_unreachable');
});

it('retries when the answer carries no message id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['unexpected' => true], 200)]);

    expect($this->provider->send(waPayload(), waConnection(), $this->token)->code)->toBe('provider_malformed_response');
});

it('verifies the X-Hub-Signature-256 header over the raw body before parsing', function () {
    [$body, $headers] = waSigned(['object' => 'whatsapp_business_account', 'entry' => []]);

    expect($this->provider->parseWebhook($body, $headers, 'app-secret'))->toBe([]);

    foreach ([
        [$body, [], 'app-secret'],
        [$body, ['x-hub-signature-256' => 'sha256=deadbeef'], 'app-secret'],
        [$body, ['x-hub-signature-256' => hash_hmac('sha256', $body, 'app-secret')], 'app-secret'],
        [$body.' ', $headers, 'app-secret'],
        [$body, $headers, 'other-secret'],
        [$body, $headers, ''],
    ] as [$raw, $given, $secret]) {
        expect(fn () => $this->provider->parseWebhook($raw, $given, $secret))->toThrow(InvalidWebhookSignature::class);
    }
});

it('turns a Meta payload into inbound, delivery, read, failure and template events', function () {
    [$body, $headers] = waSigned([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA1',
            'time' => 1760000000,
            'changes' => [
                ['field' => 'messages', 'value' => [
                    'metadata' => ['phone_number_id' => 'PN1'],
                    'contacts' => [['wa_id' => '8801711000001', 'profile' => ['name' => 'Rahim']]],
                    'messages' => [
                        ['from' => '8801711000001', 'id' => 'wamid.M1', 'timestamp' => '1760000001', 'type' => 'text', 'text' => ['body' => 'Hello']],
                        ['from' => '8801711000001', 'id' => 'wamid.M2', 'timestamp' => '1760000002', 'type' => 'image', 'image' => ['mime_type' => 'image/png', 'id' => 'MEDIA', 'caption' => 'Look']],
                        ['from' => '8801711000001', 'id' => 'wamid.M3', 'timestamp' => '1760000003', 'type' => 'interactive', 'interactive' => ['button_reply' => ['title' => 'Yes']]],
                    ],
                    'statuses' => [
                        ['id' => 'wamid.O1', 'status' => 'sent', 'recipient_id' => '8801711000001'],
                        ['id' => 'wamid.O1', 'status' => 'delivered', 'recipient_id' => '8801711000001', 'pricing' => ['billable' => true, 'category' => 'marketing']],
                        ['id' => 'wamid.O1', 'status' => 'read', 'recipient_id' => '8801711000001'],
                        ['id' => 'wamid.O2', 'status' => 'failed', 'recipient_id' => '8801711000002', 'errors' => [['code' => 131026, 'title' => 'Undeliverable']]],
                    ],
                ]],
                ['field' => 'message_template_status_update', 'value' => [
                    'event' => 'APPROVED', 'message_template_id' => 77, 'message_template_name' => 'welcome', 'message_template_language' => 'en_US', 'reason' => 'NONE',
                ]],
                ['field' => 'something_else', 'value' => ['x' => 1]],
            ],
        ]],
    ]);

    $events = $this->provider->parseWebhook($body, $headers, 'app-secret');
    $byId = collect($events)->keyBy('eventId');

    expect($events)->toHaveCount(7)
        ->and($byId['wamid.M1']->type)->toBe(ProviderEventType::Inbound)
        ->and($byId['wamid.M1']->address)->toBe('+8801711000001')
        ->and($byId['wamid.M1']->text)->toBe('Hello')
        ->and($byId['wamid.M1']->accounts)->toBe(['phone_number_id' => 'PN1'])
        ->and($byId['wamid.M1']->meta['profile_name'])->toBe('Rahim')
        ->and($byId['wamid.M2']->text)->toBe('Look')
        ->and($byId['wamid.M2']->meta['media_mime'])->toBe('image/png')
        ->and($byId['wamid.M3']->text)->toBe('Yes')
        ->and($byId['wamid.O1:delivered']->type)->toBe(ProviderEventType::Delivered)
        ->and($byId['wamid.O1:delivered']->providerMessageId)->toBe('wamid.O1')
        ->and($byId['wamid.O1:delivered']->meta['billable'])->toBeTrue()
        ->and($byId['wamid.O1:delivered']->meta['pricing_category'])->toBe('marketing')
        ->and($byId['wamid.O1:read']->type)->toBe(ProviderEventType::Read)
        ->and($byId['wamid.O2:failed']->type)->toBe(ProviderEventType::Failed)
        ->and($byId['wamid.O2:failed']->reason)->toContain('131026')
        ->and($byId->has('wamid.O1:sent'))->toBeFalse()
        ->and($byId['tpl:77:approved:1760000000']->type)->toBe(ProviderEventType::TemplateStatus)
        ->and($byId['tpl:77:approved:1760000000']->accounts)->toBe(['business_account_id' => 'WABA1'])
        ->and($byId['tpl:77:approved:1760000000']->meta['template_status'])->toBe('approved');
});

it('ignores a signed body that is not a WhatsApp business account notification', function () {
    foreach ([['object' => 'page', 'entry' => []], ['hello' => 'world'], []] as $payload) {
        [$body, $headers] = waSigned($payload);

        expect($this->provider->parseWebhook($body, $headers, 'app-secret'))->toBe([]);
    }
});

it('answers the subscription handshake only for the right token and a harmless challenge', function () {
    expect($this->provider->answerHandshake(['hub_mode' => 'subscribe', 'hub_verify_token' => 'tok', 'hub_challenge' => '12345'], 'tok'))->toBe('12345')
        ->and($this->provider->answerHandshake(['hub.mode' => 'subscribe', 'hub.verify_token' => 'tok', 'hub.challenge' => '777'], 'tok'))->toBe('777')
        ->and($this->provider->answerHandshake(['hub_mode' => 'subscribe', 'hub_verify_token' => 'bad', 'hub_challenge' => '1'], 'tok'))->toBeNull()
        ->and($this->provider->answerHandshake(['hub_mode' => 'subscribe', 'hub_verify_token' => 'tok', 'hub_challenge' => '<b>'], 'tok'))->toBeNull()
        ->and($this->provider->answerHandshake(['hub_mode' => 'subscribe', 'hub_verify_token' => '', 'hub_challenge' => '1'], ''))->toBeNull()
        ->and($this->provider->answerHandshake(['hub_mode' => 'other', 'hub_verify_token' => 'tok', 'hub_challenge' => '1'], 'tok'))->toBeNull();
});
