<?php

declare(strict_types=1);

use App\Core\Communication\Inbox\InboxMessage;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithWhatsapp;

/*
| The list's own refresh (ADR 0030): `GET /communication/inbox/poll` answers the newest page for the same filters as
| JSON, read-only, behind the inbox permission and with a throttle of its own.
*/

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('inbox-poll');
    $this->poll = fn (string $query = ''): string => $this->tenantUrl($this->tenant, '/communication/inbox/poll'.$query);
    $this->conversation = fn (string $phone, int $minutesAgo, ConversationStatus $status = ConversationStatus::Open, int $unread = 1): Conversation => $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->forceCreate([
        'phone' => $phone, 'status' => $status, 'unread_count' => $unread,
        'last_message_at' => now()->subMinutes($minutesAgo)->startOfSecond(), 'last_message_preview' => "Message from {$phone}",
    ]));
    $this->member = fn (array $permissions): array => $this->waMember($this->tenant, $permissions, ['whatsapp_marketing.conversations.view' => 'all']);
});

it('answers the newest page of the list as JSON, for the same filters as the page', function () {
    $newer = ($this->conversation)('+8801711000001', 5);
    ($this->conversation)('+8801711000002', 10);
    ($this->conversation)('+8801711000003', 15, ConversationStatus::Closed);
    [$agent] = ($this->member)(['communication.inbox.view', 'whatsapp_marketing.conversations.view']);

    $this->actingAsMember($this->tenant, $agent)->getJson(($this->poll)())
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('next', null)
        ->assertJsonPath('items.0.key', 'whatsapp_marketing.conversations:'.$newer->id)
        ->assertJsonPath('items.0.preview', 'Message from +8801711000001')
        ->assertJsonPath('items.0.unread', 1)
        ->assertJsonPath('items.0.state', 'unassigned')
        ->assertJsonPath('items.0.chat', true)
        ->assertJsonPath('items.0.sourceLabel', 'WhatsApp');

    $this->actingAsMember($this->tenant, $agent)->getJson(($this->poll)('?state=closed'))
        ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.state', 'closed');
    $this->actingAsMember($this->tenant, $agent)->getJson(($this->poll)('?owner=me'))
        ->assertOk()->assertJsonCount(0, 'items');
    $this->actingAsMember($this->tenant, $agent)->getJson(($this->poll)('?search=000002'))
        ->assertOk()->assertJsonCount(1, 'items');

    // The same rows as the page's own first page.
    $page = $this->actingAsMember($this->tenant, $agent)->get($this->tenantUrl($this->tenant, '/communication/inbox'))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->has('list.items', 2));
    expect($page->viewData('page')['props']['list']['items'])
        ->toBe($this->actingAsMember($this->tenant, $agent)->getJson(($this->poll)())->json('items'));
});

it('is read-only: polling never marks a conversation read or changes it', function () {
    $conversation = ($this->conversation)('+8801711000011', 5, unread: 3);
    $before = $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->findOrFail($conversation->id));
    [$agent] = ($this->member)(['communication.inbox.view', 'whatsapp_marketing.conversations.view']);

    $this->actingAsMember($this->tenant, $agent)->getJson(($this->poll)())->assertOk()->assertJsonPath('items.0.unread', 3);
    $this->actingAsMember($this->tenant, $agent)->getJson(($this->poll)())->assertOk()->assertJsonPath('items.0.unread', 3);

    $after = $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->findOrFail($conversation->id));
    expect($after->unread_count)->toBe(3)
        ->and($after->updated_at?->toIso8601String())->toBe($before->updated_at?->toIso8601String())
        ->and($after->last_message_at?->toIso8601String())->toBe($before->last_message_at?->toIso8601String());

    // Only GET exists.
    $this->actingAsMember($this->tenant, $agent)->postJson(($this->poll)())->assertStatus(405);
});

it('needs the inbox permission, and shows only the sources the member may use', function () {
    ($this->conversation)('+8801711000021', 5);
    [$outsider] = ($this->member)(['whatsapp_marketing.conversations.view']);
    [$noSource] = ($this->member)(['communication.inbox.view']);

    $this->actingAsMember($this->tenant, $outsider)->getJson(($this->poll)())->assertForbidden();
    $this->actingAsMember($this->tenant, $noSource)->getJson(($this->poll)())->assertOk()->assertJsonCount(0, 'items');
    // A source the member does not have is not a filter they can use.
    $this->actingAsMember($this->tenant, $noSource)->getJson(($this->poll)('?source=whatsapp_marketing.conversations'))->assertUnprocessable()->assertJsonValidationErrors('source');
    $this->actingAsMember($this->tenant, $noSource)->getJson(($this->poll)('?state=everything'))->assertUnprocessable()->assertJsonValidationErrors('state');

    $this->startNewBrowserSession()->getJson(($this->poll)())->assertUnauthorized();
});

it('never shows another workspace\'s conversations', function () {
    $mine = ($this->conversation)('+8801711000031', 5);
    $intruder = $this->waTenant('inbox-poll-intruder');

    $this->startNewBrowserSession()->actingAsOwner($intruder)
        ->getJson($this->tenantUrl($intruder, '/communication/inbox/poll?state=all'))
        ->assertOk()
        ->assertJsonCount(0, 'items');
    $this->startNewBrowserSession()->actingAsOwner($this->tenant)
        ->getJson(($this->poll)('?state=all'))
        ->assertOk()
        ->assertJsonPath('items.0.key', 'whatsapp_marketing.conversations:'.$mine->id);
});

it('has a throttle of its own, so a tab left open never uses up the budget for replies and reads', function () {
    $conversation = ($this->conversation)('+8801711000041', 5);
    [$agent] = ($this->member)(['communication.inbox.view', 'whatsapp_marketing.conversations.view']);
    $this->actingAsMember($this->tenant, $agent);

    $this->getJson(($this->poll)())->assertOk()->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-RateLimit-Remaining', '59');

    for ($i = 1; $i < 60; $i++) {
        $this->getJson(($this->poll)())->assertOk();
    }

    $this->getJson(($this->poll)())->assertTooManyRequests();

    // The mark-read POST counts against its own limit, not the poll's.
    $this->postJson($this->tenantUrl($this->tenant, "/communication/inbox/whatsapp_marketing.conversations/{$conversation->id}/read"))
        ->assertNoContent()
        ->assertHeader('X-RateLimit-Limit', '120');
});

it('names the kind of an attachment in an open thread, from what the source stores', function () {
    $conversation = ($this->conversation)('+8801711000051', 5);
    $this->inTenant($this->tenant, fn () => Message::query()->forceCreate([
        'conversation_id' => $conversation->id, 'direction' => Direction::Inbound, 'sender_type' => SenderType::Customer,
        'type' => MessageType::Image, 'body' => null, 'status' => MessageStatus::Received, 'occurred_at' => now()->subMinutes(5),
    ]));

    $this->actingAsOwner($this->tenant)
        ->get($this->tenantUrl($this->tenant, '/communication/inbox?open=whatsapp_marketing.conversations:'.$conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.messages.0.attachment', 'Image')
            ->where('thread.messages.0.attachmentKind', 'image'));

    expect(fn () => new InboxMessage('x', InboxMessage::IN, null, CarbonImmutable::now(), attachmentKind: 'executable'))
        ->toThrow(InvalidArgumentException::class);
});
