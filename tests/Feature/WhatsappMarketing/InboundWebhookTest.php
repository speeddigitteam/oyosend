<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Communication\Data\ChannelEventType;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Models\WebhookEvent;
use App\Modules\WhatsappMarketing\Actions\ChannelEventHandler;
use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\SettingsManager;
use App\Modules\WhatsappMarketing\Actions\WindowTracker;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Models\Window;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-inbound');
    $this->waConnectCloud($this->tenant, wamid: 'wamid.OUT-FIXED-1');
    $this->contact = $this->waContact($this->tenant, ['phone' => '+880 1711-000001']);
    $this->url = $this->tenantUrl($this->tenant, '/communication/webhooks/whatsapp_cloud');
});

it('answers the subscription handshake only with the configured verify token', function () {
    $this->get($this->url.'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1158201444')
        ->assertOk()
        ->assertSee('1158201444', false);

    $this->get($this->url.'?hub.mode=subscribe&hub.verify_token=guess&hub.challenge=1158201444')->assertForbidden();
    $this->get($this->url.'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=%3Cscript%3E')->assertForbidden();
    $this->get($this->url.'?hub.mode=unsubscribe&hub.verify_token=verify-me&hub.challenge=1')->assertForbidden();
});

it('has no handshake for a workspace that never connected the channel', function () {
    $other = $this->waTenant('wa-inbound-other');

    $this->get($this->tenantUrl($other, '/communication/webhooks/whatsapp_cloud?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1'))
        ->assertNotFound();
});

it('rejects an unsigned, wrongly signed or tampered delivery and stores nothing', function () {
    $payload = $this->waMessagePayload('109876543210', [[
        'from' => '8801711000001', 'id' => 'wamid.IN1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Hello'],
    ]], '8801711000001');

    $this->waWebhook($this->tenant, $payload, secret: null)->assertUnauthorized();
    $this->waWebhook($this->tenant, $payload, secret: 'wrong-secret')->assertUnauthorized();
    $this->waWebhook($this->tenant, $payload, signature: 'sha256=abc123')->assertUnauthorized();
    $this->waWebhook($this->tenant, $payload, signature: hash_hmac('sha256', json_encode($payload), 'app-secret-test'))->assertUnauthorized();

    $this->inTenant($this->tenant, function (): void {
        expect(Conversation::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(WebhookEvent::query()->count())->toBe(0);
    });
});

it('records an inbound text as a conversation with a window, linked to the matching contact', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Hello, is this still available?', 'wamid.IN-A')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->firstOrFail();
        $message = Message::query()->firstOrFail();

        expect($conversation->phone)->toBe('+8801711000001')
            ->and($conversation->contact_id)->toBe($this->contact->id)
            ->and($conversation->profile_name)->toBe('Rahim Uddin')
            ->and($conversation->status)->toBe(ConversationStatus::Open)
            ->and($conversation->unread_count)->toBe(1)
            ->and($conversation->owner_id)->toBeNull()
            ->and($conversation->last_inbound_at)->not->toBeNull()
            ->and($message->body)->toBe('Hello, is this still available?')
            ->and($message->provider_message_id)->toBe('wamid.IN-A')
            ->and($message->status)->toBe(MessageStatus::Received)
            ->and(Window::query()->count())->toBe(1)
            ->and(app(UsageLimits::class)->used(WindowTracker::LIMIT))->toBe(1);
    });
});

it('counts a repeated provider message once, however often it is delivered', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Hi', 'wamid.IN-DUP')->assertOk();
    $this->waInbound($this->tenant, '+8801711000001', 'Hi', 'wamid.IN-DUP')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        expect(Message::query()->count())->toBe(1)
            ->and(Window::query()->count())->toBe(1)
            ->and(Conversation::query()->firstOrFail()->unread_count)->toBe(1);
    });
});

it('does not duplicate even when the platform-level duplicate check is bypassed', function () {
    $event = new ChannelEvent(
        channel: MarketingChannel::Whatsapp,
        provider: 'whatsapp_cloud',
        eventId: 'wamid.IN-BYPASS',
        type: ChannelEventType::Inbound,
        address: '+8801711000001',
        text: 'Hello',
        details: ['message_type' => 'text'],
    );

    $this->inTenant($this->tenant, function () use ($event): void {
        $handler = app(ChannelEventHandler::class);
        $handler->handle($event);
        $handler->handle($event);

        expect(Message::query()->count())->toBe(1)->and(Conversation::query()->count())->toBe(1);
    });
});

it('opens one window for several messages inside 24 hours and a new one after it closed', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'one')->assertOk();
    $this->waInbound($this->tenant, '+8801711000001', 'two')->assertOk();

    $this->inTenant($this->tenant, fn () => expect(Window::query()->count())->toBe(1));

    $this->travel(25)->hours();
    $this->waInbound($this->tenant, '+8801711000001', 'three')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        expect(Window::query()->count())->toBe(2)
            ->and(Conversation::query()->count())->toBe(1)
            ->and(app(UsageLimits::class)->used(WindowTracker::LIMIT))->toBe(2);
    });
});

it('keeps a number that is not a contact as a conversation with the number only', function () {
    $this->waInbound($this->tenant, '+8801999000002', 'Who are you?', name: 'Stranger')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->where('phone', '+8801999000002')->firstOrFail();

        expect($conversation->contact_id)->toBeNull()
            ->and($conversation->profile_name)->toBe('Stranger');
    });
});

it('adds an unknown number to the shared contacts only when the workspace chose that', function () {
    $this->inTenant($this->tenant, fn () => app(SettingsManager::class)->update(['auto_create_contacts' => true]));

    $this->waInbound($this->tenant, '+8801999000003', 'Hello', name: 'New Lead')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->where('phone', '+8801999000003')->firstOrFail();
        $contact = Contact::query()->findOrFail($conversation->contact_id);

        expect($contact->first_name)->toBe('New Lead')->and($contact->phone)->toBe('+8801999000003');
    });
});

it('keeps attachments as metadata only and never fetches them', function () {
    $payload = $this->waMessagePayload('109876543210', [[
        'from' => '8801711000001', 'id' => 'wamid.IN-IMG', 'timestamp' => (string) now()->timestamp, 'type' => 'image',
        'image' => ['mime_type' => 'image/jpeg', 'sha256' => 'abc', 'id' => 'MEDIA-123'],
    ]], '8801711000001');

    $this->waWebhook($this->tenant, $payload)->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $message = Message::query()->firstOrFail();

        // Metadata only (ADR 0022 keeps the provider media id so a controller can download it later on demand);
        // the webhook never carries or fetches the bytes.
        expect($message->type)->toBe(MessageType::Image)
            ->and($message->body)->toBeNull()
            ->and($message->attachments)->toHaveCount(1)
            ->and($message->attachments[0])->toMatchArray(['kind' => 'image', 'mime' => 'image/jpeg', 'has_caption' => false, 'provider_media_id' => 'MEDIA-123', 'downloadable' => true])
            ->and($message->attachments[0])->not->toHaveKey('url')
            ->and($message->attachments[0])->not->toHaveKey('bytes');
    });

    Http::assertNothingSent();
});

it('cleans and caps the text of an inbound message', function () {
    $dirty = "Hi\x00\x07 there\r\n".str_repeat('a', 6000);

    $this->waInbound($this->tenant, '+8801711000001', $dirty)->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $body = (string) Message::query()->firstOrFail()->body;

        expect(mb_strlen($body))->toBeLessThanOrEqual(Messenger::MAX_TEXT)
            ->and($body)->not->toContain("\x00")
            ->and($body)->not->toContain("\x07")
            ->and($body)->toStartWith("Hi there\n");
    });
});

it('drops events that belong to another phone number of a shared provider app', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Not for this workspace', phoneNumberId: '999999999999')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        expect(Conversation::query()->count())->toBe(0)->and(Message::query()->count())->toBe(0);
    });
});

it('does nothing for a workspace that is not entitled to the app, but still logs the platform timeline', function () {
    $plain = $this->commTenant('wa-inbound-plain');
    $this->entitle($plain, 'communication');
    $this->waConnectCloud($plain);
    $this->waContact($plain, ['phone' => '+8801711000001']);

    $this->waInbound($plain, '+8801711000001', 'Hello')->assertOk();

    $this->inTenant($plain, fn () => expect(Conversation::query()->count())->toBe(0));
});

it('tracks delivery, read receipts and provider-reported billing on our outbound message', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Hi')->assertOk();

    $message = $this->inTenant($this->tenant, function (): Message {
        $conversation = Conversation::query()->firstOrFail();

        return app(Messenger::class)->reply($conversation, 'Thanks for writing', null);
    });

    $this->inTenant($this->tenant, fn () => expect($message->fresh()->status)->toBe(MessageStatus::Sent));

    $this->waStatus($this->tenant, 'wamid.OUT-FIXED-1', 'delivered', [
        'pricing' => ['billable' => true, 'category' => 'service'],
        'conversation' => ['id' => 'c1', 'origin' => ['type' => 'service']],
    ])->assertOk();

    $this->inTenant($this->tenant, function () use ($message): void {
        $fresh = $message->fresh();

        expect($fresh->status)->toBe(MessageStatus::Delivered)
            ->and($fresh->billable)->toBeTrue()
            ->and($fresh->pricing_category)->toBe('service')
            ->and($fresh->delivered_at)->not->toBeNull();
    });

    $this->waStatus($this->tenant, 'wamid.OUT-FIXED-1', 'read')->assertOk();

    $this->inTenant($this->tenant, function () use ($message): void {
        $fresh = $message->fresh();

        expect($fresh->status)->toBe(MessageStatus::Read)->and($fresh->read_at)->not->toBeNull();
    });
});

it('never moves a message backward when receipts arrive late or out of order', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Hi')->assertOk();
    $message = $this->inTenant($this->tenant, fn () => app(Messenger::class)->reply(Conversation::query()->firstOrFail(), 'Hello', null));

    $this->waStatus($this->tenant, 'wamid.OUT-FIXED-1', 'read')->assertOk();
    $this->waStatus($this->tenant, 'wamid.OUT-FIXED-1', 'delivered')->assertOk();

    $this->inTenant($this->tenant, fn () => expect($message->fresh()->status)->toBe(MessageStatus::Read));
});

it('records a provider failure with its reason', function () {
    $this->waInbound($this->tenant, '+8801711000001', 'Hi')->assertOk();
    $message = $this->inTenant($this->tenant, fn () => app(Messenger::class)->reply(Conversation::query()->firstOrFail(), 'Hello', null));

    $this->waStatus($this->tenant, 'wamid.OUT-FIXED-1', 'failed', [
        'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
    ])->assertOk();

    $this->inTenant($this->tenant, function () use ($message): void {
        $fresh = $message->fresh();

        expect($fresh->status)->toBe(MessageStatus::Failed)->and($fresh->failure_code)->not->toBeNull();
    });
});

it('updates a template when the provider reports its approval status, for this business account only', function () {
    $template = $this->waTemplate($this->tenant, ['name' => 'welcome_offer', 'status' => 'pending']);

    $event = fn (string $accountId, string $status) => $this->waWebhook($this->tenant, [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => $accountId,
            'time' => 1760000000,
            'changes' => [[
                'field' => 'message_template_status_update',
                'value' => [
                    'event' => $status,
                    'message_template_id' => 4242,
                    'message_template_name' => 'welcome_offer',
                    'message_template_language' => 'en_US',
                    'reason' => 'NONE',
                ],
            ]],
        ]],
    ]);

    $event('777000999', 'REJECTED')->assertOk();
    $this->inTenant($this->tenant, fn () => expect($template->fresh()->status)->toBe(TemplateStatus::Pending));

    $event('555000111', 'APPROVED')->assertOk();

    $this->inTenant($this->tenant, function () use ($template): void {
        $fresh = Template::query()->findOrFail($template->id);

        expect($fresh->status)->toBe(TemplateStatus::Approved)
            ->and($fresh->status_source->value)->toBe('provider')
            ->and($fresh->provider_template_id)->toBe('4242');
    });
});
