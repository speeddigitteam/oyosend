<?php

declare(strict_types=1);

use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Actions\UnifiedInbox;
use App\Modules\Support\Actions\LiveChatManager;
use App\Modules\Support\Actions\LiveChatSettings;
use App\Modules\Support\Enums\ChatDirection;
use App\Modules\Support\Enums\ChatStatus;
use App\Modules\Support\Enums\VisitorDetails;
use App\Modules\Support\Events\LiveChatStarted;
use App\Modules\Support\Inbox\LiveChatInboxSource;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\Support\Models\ChatSetting;
use App\Modules\Support\Support\ChatOrigins;
use App\Modules\Support\Support\LiveChatLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSupport;

/*
| Website live chat (ADR 0032): the widget settings, the stateless public widget, frame and JSON API on the workspace
| host, and the member side (Support "Live chat" page and the unified inbox source), which share LiveChatManager.
*/

uses(InteractsWithSupport::class);

beforeEach(function () {
    // Rate limits must survive the context switch of every request.
    $this->useDatabaseCache();
    $this->tenant = $this->supportTenant('sup-chat');

    $this->enable = function (array $overrides = [], ?Tenant $tenant = null): ChatSetting {
        return $this->inTenant($tenant ?? $this->tenant, fn (): ChatSetting => app(LiveChatSettings::class)->update([
            'enabled' => true,
            'allowed_origins' => ['https://www.example.com', 'https://shop.example.com'],
            'widget_title' => 'Talk to Acme',
            'greeting' => 'Hello! Ask us anything.',
            'visitor_details' => VisitorDetails::Optional,
            'offline_note' => 'We answer within a day.',
            ...$overrides,
        ]));
    };
    $this->origin = fn (?Tenant $tenant = null): string => rtrim($this->tenantUrl($tenant ?? $this->tenant, '/'), '/');
    $this->public = fn (string $path, ?Tenant $tenant = null): string => $this->tenantUrl($tenant ?? $this->tenant, '/support/chat/'.$path);
    $this->headers = fn (?string $token = null, ?Tenant $tenant = null): array => array_filter([
        'Origin' => ($this->origin)($tenant),
        'Accept' => 'application/json',
        'X-Chat-Visitor' => $token,
    ]);
    $this->start = fn (array $data = [], ?Tenant $tenant = null): TestResponse => $this->postJson(
        ($this->public)('api/conversations', $tenant),
        ['text' => 'Hello, is anyone there?', ...$data],
        ($this->headers)(null, $tenant),
    );
    $this->send = fn (string $token, string $text, ?Tenant $tenant = null): TestResponse => $this->postJson(
        ($this->public)('api/messages', $tenant),
        ['text' => $text],
        ($this->headers)($token, $tenant),
    );
    $this->poll = fn (string $token, ?string $after = null, ?Tenant $tenant = null): TestResponse => $this->getJson(
        ($this->public)('api/messages'.($after === null ? '' : '?after='.$after), $tenant),
        ($this->headers)($token, $tenant),
    );
    $this->conversation = fn (?Tenant $tenant = null): ChatConversation => $this->inTenant($tenant ?? $this->tenant, fn (): ChatConversation => ChatConversation::query()->latest('id')->firstOrFail());
    $this->page = fn (string $query = ''): string => $this->supportUrl($this->tenant, '/live-chat'.$query);
});

// ---- availability ------------------------------------------------------------------------------------------------------

it('answers 404 for the widget, the frame and the API while the chat is not set up or switched off', function () {
    $this->get(($this->public)('widget.js'))->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
    $this->get(($this->public)('frame'))->assertNotFound();
    ($this->start)()->assertNotFound();

    ($this->enable)(['enabled' => false]);

    $this->get(($this->public)('widget.js'))->assertNotFound();
    $this->get(($this->public)('frame'))->assertNotFound();
    ($this->start)()->assertNotFound();

    // Nothing was created by the visitor's attempts.
    expect($this->inTenant($this->tenant, fn () => ChatConversation::query()->count()))->toBe(0);
});

it('answers 404 in a workspace that is not entitled to Support, whatever its settings say', function () {
    $plain = $this->createTenant('sup-chat-plain');
    ($this->enable)([], $plain);

    $this->get(($this->public)('widget.js', $plain))->assertNotFound();
    $this->get(($this->public)('frame', $plain))->assertNotFound();
    ($this->start)([], $plain)->assertNotFound();
});

it('serves a small loader script with the frame address and a short cache', function () {
    ($this->enable)();

    $response = $this->get(($this->public)('widget.js'))->assertOk();
    $script = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('application/javascript; charset=utf-8')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->getCookies())->toBe([])
        ->and($script)->toContain(str_replace('/', '\/', ($this->public)('frame')))
        ->and($script)->toContain('event.origin !== config.origin')
        ->and($script)->toContain('Talk to Acme')
        ->and($script)->not->toContain('innerHTML');
});

it('lets only the allowed origins frame the chat page, under a per-response nonce', function () {
    ($this->enable)();

    $first = $this->get(($this->public)('frame'))->assertOk();
    $csp = (string) $first->headers->get('Content-Security-Policy');

    expect($csp)->toContain('frame-ancestors https://www.example.com https://shop.example.com')
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("form-action 'none'")
        ->and($first->headers->has('X-Frame-Options'))->toBeFalse()
        ->and($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->headers->getCookies())->toBe([]);

    preg_match("/script-src 'nonce-([^']+)'/", $csp, $nonce);
    $html = (string) $first->getContent();

    expect($nonce[1] ?? null)->not->toBeNull()
        ->and($html)->toContain('<script nonce="'.$nonce[1].'">')
        ->and($html)->toContain('<style nonce="'.$nonce[1].'">')
        ->and($html)->toContain('Talk to Acme')
        ->and($html)->toContain('Hello! Ask us anything.')
        ->and($html)->not->toContain('innerHTML')
        ->and($html)->not->toContain('_token');

    // A fresh nonce every time.
    $second = (string) $this->get(($this->public)('frame'))->headers->get('Content-Security-Policy');
    expect($second)->not->toBe($csp);

    // No allowed origin: nobody may frame it.
    $this->inTenant($this->tenant, fn () => ChatSetting::query()->update(['allowed_origins' => json_encode([])]));
    $denied = $this->get(($this->public)('frame'))->assertOk();
    expect((string) $denied->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($denied->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('escapes the workspace texts on the frame page', function () {
    ($this->enable)(['widget_title' => '<b>Acme</b>', 'greeting' => '<img src=x onerror=alert(1)>']);

    $html = (string) $this->get(($this->public)('frame'))->assertOk()->getContent();

    expect($html)->not->toContain('<img src=x')->and($html)->not->toContain('<b>Acme</b>')->and($html)->toContain('&lt;b&gt;Acme&lt;/b&gt;');
});

// ---- starting, the token, sending and polling ----------------------------------------------------------------------------

it('starts a conversation, returns the token once and stores only its hash', function () {
    Event::fake([LiveChatStarted::class]);
    ($this->enable)();

    $response = ($this->start)(['name' => 'Nadia', 'email' => 'Nadia@Example.test', 'page_url' => 'https://www.example.com/pricing?coupon=SECRET#top'])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('messages.0.direction', 'in')
        ->assertJsonPath('messages.0.text', 'Hello, is anyone there?');
    $token = (string) $response->json('token');

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($response->headers->getCookies())->toBe([]);

    $row = DB::table('support_chat_conversations')->sole();
    $everything = json_encode([$row, DB::table('support_chat_messages')->get(), DB::table('audit_logs')->get()]);
    $chatRows = json_encode([$row, DB::table('support_chat_messages')->get()]);

    expect($row->visitor_token_hash)->toBe(hash('sha256', $token))
        ->and($everything)->not->toContain($token)
        ->and($row->name)->toBe('Nadia')
        ->and($row->email)->toBe('nadia@example.test')
        // The page without its query or fragment, and no address of the visitor anywhere.
        ->and($row->page_url)->toBe('https://www.example.com/pricing')
        ->and($everything)->not->toContain('SECRET')
        ->and($chatRows)->not->toContain('127.0.0.1')
        ->and($row->unread_count)->toBe(1)
        ->and($row->status)->toBe('open');

    // A visitor writes nothing to the audit trail (it would record their address): only the member's settings save is there.
    expect($this->auditTrail($this->tenant)->pluck('event')->filter(fn (string $event): bool => str_starts_with($event, 'support.'))->unique()->values()->all())
        ->toBe(['support.live_chat.settings_saved']);

    // No shared contact is created from anonymous input.
    expect($this->inTenant($this->tenant, fn () => DB::table('contacts')->where('tenant_id', $this->tenant->id)->count()))->toBe(0);
    Event::assertDispatched(LiveChatStarted::class, fn (LiveChatStarted $event) => $event->payload() === ['conversation_id' => $row->id]);
});

it('sends and polls with the token, after a given message, as plain text', function () {
    ($this->enable)();
    $token = (string) ($this->start)()->json('token');
    $first = (string) ($this->poll)($token)->assertOk()->json('messages.0.id');

    $sent = ($this->send)($token, "  <script>alert(1)</script>\u{0007}\u{202E}\r\nsecond line  ")->assertCreated();

    expect($sent->json('message.text'))->toBe("<script>alert(1)</script>\nsecond line")
        ->and($sent->json('message.direction'))->toBe('in');

    ($this->poll)($token, $first)->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.text', "<script>alert(1)</script>\nsecond line")
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('more', false);

    // Without `after`: the newest messages, oldest first.
    ($this->poll)($token)->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.id', $first);

    expect(($this->conversation)()->unread_count)->toBe(2);
});

it('refuses empty, overlong and non-text messages', function () {
    ($this->enable)();
    $token = (string) ($this->start)()->json('token');

    ($this->send)($token, "  \u{0000}\n ")->assertStatus(422)->assertJsonPath('errors.text.0', 'Write a message.');
    ($this->send)($token, str_repeat('a', LiveChatLimits::MAX_TEXT + 1))->assertStatus(422);
    $this->postJson(($this->public)('api/messages'), ['text' => ['an', 'array']], ($this->headers)($token))->assertStatus(422);
    ($this->send)($token, str_repeat('a', LiveChatLimits::MAX_TEXT))->assertCreated();
    ($this->start)(['text' => ''])->assertStatus(422);
});

it('answers 404 to a wrong, missing or foreign token and never crosses workspaces', function () {
    ($this->enable)();
    $token = (string) ($this->start)()->json('token');
    $other = $this->supportTenant('sup-chat-other');
    ($this->enable)([], $other);

    ($this->poll)(Str::random(43))->assertNotFound();
    ($this->send)('not-a-token', 'Hi')->assertNotFound();
    $this->getJson(($this->public)('api/messages'), ['Origin' => ($this->origin)(), 'Accept' => 'application/json'])->assertNotFound();

    // The same token on another workspace's host reaches nothing there.
    ($this->poll)($token, null, $other)->assertNotFound();
    ($this->send)($token, 'Wrong workspace', $other)->assertNotFound();

    expect($this->inTenant($other, fn () => ChatMessage::query()->count()))->toBe(0)
        ->and($this->inTenant($this->tenant, fn () => ChatMessage::query()->count()))->toBe(1);
});

it('refuses the API from another origin, and a request that says nothing about its origin', function () {
    ($this->enable)();
    $token = (string) ($this->start)()->json('token');

    $this->postJson(($this->public)('api/conversations'), ['text' => 'Hi'], ['Origin' => 'https://www.example.com', 'Accept' => 'application/json'])->assertForbidden();
    $this->postJson(($this->public)('api/messages'), ['text' => 'Hi'], ['Origin' => 'https://evil.example', 'Accept' => 'application/json', 'X-Chat-Visitor' => $token])->assertForbidden();
    $this->postJson(($this->public)('api/messages'), ['text' => 'Hi'], ['Accept' => 'application/json', 'X-Chat-Visitor' => $token])->assertForbidden();
    $this->getJson(($this->public)('api/messages'), ['Sec-Fetch-Site' => 'cross-site', 'Accept' => 'application/json', 'X-Chat-Visitor' => $token])->assertForbidden();

    // The frame's own same-origin GET carries no Origin header, only Sec-Fetch-Site.
    $this->getJson(($this->public)('api/messages'), ['Sec-Fetch-Site' => 'same-origin', 'Accept' => 'application/json', 'X-Chat-Visitor' => $token])->assertOk();

    expect($this->inTenant($this->tenant, fn () => ChatMessage::query()->count()))->toBe(1);
});

it('turns away a filled honeypot without recording anything', function () {
    ($this->enable)();

    ($this->start)(['website' => 'https://spam.example'])->assertStatus(422);

    expect($this->inTenant($this->tenant, fn () => ChatConversation::query()->count()))->toBe(0);
});

it('keeps one conversation per token', function () {
    ($this->enable)();
    $token = (string) ($this->start)()->json('token');

    $this->postJson(($this->public)('api/conversations'), ['text' => 'Another one'], ($this->headers)($token))->assertStatus(409);

    expect($this->inTenant($this->tenant, fn () => ChatConversation::query()->count()))->toBe(1);
});

it('enforces the visitor details the workspace asks for', function () {
    ($this->enable)(['visitor_details' => VisitorDetails::Required]);

    ($this->start)()->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
    ($this->start)(['name' => 'Nadia', 'email' => 'not-an-address'])->assertStatus(422)->assertJsonValidationErrors(['email']);
    ($this->start)(['name' => str_repeat('n', 101), 'email' => 'nadia@example.test'])->assertStatus(422)->assertJsonValidationErrors(['name']);
    ($this->start)(['name' => "Nadia\nKarim", 'email' => 'nadia@example.test'])->assertCreated();

    expect(($this->conversation)()->name)->toBe('Nadia Karim');

    // Not asked: whatever is sent is ignored.
    ($this->enable)(['visitor_details' => VisitorDetails::None]);
    ($this->start)(['name' => 'Ignored', 'email' => 'ignored@example.test'])->assertCreated();

    $latest = ($this->conversation)();
    expect($latest->name)->toBeNull()->and($latest->email)->toBeNull();
});

// ---- abuse limits ---------------------------------------------------------------------------------------------------------

it('caps new conversations per address per hour', function () {
    ($this->enable)();

    foreach (range(1, LiveChatLimits::STARTS_PER_ADDRESS_PER_HOUR) as $attempt) {
        ($this->start)(['text' => "Hello {$attempt}"])->assertCreated();
    }

    ($this->start)(['text' => 'One too many'])->assertStatus(429)->assertHeader('Retry-After');

    expect($this->inTenant($this->tenant, fn () => ChatConversation::query()->count()))->toBe(LiveChatLimits::STARTS_PER_ADDRESS_PER_HOUR);
});

it('throttles messages per token', function () {
    ($this->enable)();
    $token = (string) ($this->start)()->json('token');

    foreach (range(1, LiveChatLimits::SENDS_PER_TOKEN_PER_MINUTE) as $attempt) {
        ($this->send)($token, "Message {$attempt}")->assertCreated();
    }

    ($this->send)($token, 'Too fast')->assertStatus(429);

    expect(($this->conversation)()->message_count)->toBe(LiveChatLimits::SENDS_PER_TOKEN_PER_MINUTE + 1);
});

// ---- members ------------------------------------------------------------------------------------------------------------------

it('shows a member reply from the unified inbox in the visitor\'s next poll', function () {
    ($this->enable)();
    $this->entitle($this->tenant, 'communication');
    $token = (string) ($this->start)()->json('token');
    $last = (string) ($this->poll)($token)->json('messages.0.id');
    $conversation = ($this->conversation)();

    $this->actingAsOwner($this->tenant)
        ->get($this->tenantUrl($this->tenant, '/communication/inbox?open=support.live_chat:'.$conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.source', 'support.live_chat')
            ->where('thread.channel', 'web')
            ->where('thread.canReply', true)
            ->where('thread.messages.0.text', 'Hello, is anyone there?'));

    $this->actingAsOwner($this->tenant)
        ->post($this->tenantUrl($this->tenant, "/communication/inbox/support.live_chat/{$conversation->id}/reply"), ['text' => 'Hi! How can we help?'])
        ->assertRedirect();

    $this->startNewBrowserSession();
    ($this->poll)($token, $last)->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.direction', 'out')
        ->assertJsonPath('messages.0.text', 'Hi! How can we help?')
        // Members are not named to the visitor.
        ->assertJsonMissingPath('messages.0.author');

    $fresh = ($this->conversation)();
    expect($fresh->assignee_membership_id)->toBe($this->membershipOf($this->tenant, $this->ownerOf($this->tenant))->id)
        ->and($fresh->unread_count)->toBe(0);
});

it('answers from the Support page, closes, and reopens when the visitor writes again', function () {
    ($this->enable)();
    $token = (string) ($this->start)()->json('token');
    $conversation = ($this->conversation)();

    $this->actingAsOwner($this->tenant)
        ->get(($this->page)('?open='.$conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Support/Pages/LiveChat/Index')
            ->where('enabled', true)
            ->where('thread.id', $conversation->id)
            ->where('thread.canReply', true)
            ->where('thread.canClose', true)
            ->where('thread.messages.0.direction', 'in')
            ->where('conversations.items.0.id', $conversation->id)
            // Opening the thread read it.
            ->where('conversations.items.0.unread', 0));

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$conversation->id}/messages"), ['text' => 'Hello from the desk'])->assertRedirect();
    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$conversation->id}/close"))->assertRedirect();

    $closed = ($this->conversation)();
    expect($closed->status)->toBe(ChatStatus::Closed)->and($closed->closed_at)->not->toBeNull();
    expect($this->auditTrail($this->tenant, 'support.live_chat.closed'))->toHaveCount(1);

    // A closed chat cannot be answered by a member; the reason says how it opens again.
    $this->actingAsOwner($this->tenant)->from(($this->page)())->post(($this->page)("/{$conversation->id}/messages"), ['text' => 'Late'])
        ->assertSessionHasErrors(['text' => LiveChatManager::closedReason()]);
    $this->actingAsOwner($this->tenant)->get(($this->page)('?open='.$conversation->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canReply', false)->where('thread.replyBlockedReason', LiveChatManager::closedReason()));

    $this->startNewBrowserSession();
    ($this->poll)($token)->assertJsonPath('status', 'closed');
    ($this->send)($token, 'One more question')->assertCreated();

    $reopened = ($this->conversation)();
    expect($reopened->status)->toBe(ChatStatus::Open)
        ->and($reopened->closed_at)->toBeNull()
        ->and($reopened->unread_count)->toBe(1);
    ($this->poll)($token)->assertJsonPath('status', 'open')->assertJsonCount(3, 'messages');
});

it('maps chats onto the inbox states and clears unread on markRead', function () {
    ($this->enable)();
    $owner = $this->ownerOf($this->tenant);
    $ownerMembership = $this->membershipOf($this->tenant, $owner);
    $tokens = [];

    foreach (['first', 'second', 'third'] as $text) {
        $tokens[] = (string) ($this->start)(['text' => $text])->json('token');
    }

    $ids = $this->inTenant($this->tenant, fn () => ChatConversation::query()->orderBy('id')->pluck('id')->all());
    $this->inTenant($this->tenant, function () use ($ids, $ownerMembership): void {
        $chats = app(LiveChatManager::class);
        $chats->reply(ChatConversation::query()->findOrFail($ids[1]), 'Assigned by replying', $ownerMembership);
        $chats->close(ChatConversation::query()->findOrFail($ids[2]), $ownerMembership);
    });
    ($this->send)($tokens[0], 'Anyone?')->assertCreated();

    $this->inTenant($this->tenant, function () use ($owner, $ownerMembership, $ids): void {
        $source = app(LiveChatInboxSource::class);
        $query = new InboxQuery($owner, $ownerMembership->id, InboxState::cases());

        expect($source->counts($query))->toBe(['unassigned' => 1, 'active' => 1, 'closed' => 1]);

        $items = collect($source->items($query))->keyBy('id');
        expect($items[$ids[0]]->state)->toBe(InboxState::Unassigned)
            ->and($items[$ids[0]]->unread)->toBe(2)
            ->and($items[$ids[0]]->preview)->toBe('Anyone?')
            ->and($items[$ids[0]]->url)->toContain('/support/live-chat?open='.$ids[0])
            ->and($items[$ids[1]]->state)->toBe(InboxState::Active)
            ->and($items[$ids[1]]->unread)->toBe(0)
            ->and($items[$ids[2]]->state)->toBe(InboxState::Closed);

        $thread = $source->thread($ids[2], $owner);
        expect($thread?->canReply)->toBeFalse()
            ->and($thread?->replyBlockedReason)->toBe(LiveChatManager::closedReason())
            ->and($thread?->channel)->toBe('web');

        $source->markRead($ids[0], $owner);
        expect(ChatConversation::query()->findOrFail($ids[0])->unread_count)->toBe(0);

        // Through the inbox action as well: the reply goes through the same manager.
        $message = app(UnifiedInbox::class)->reply('support.live_chat', $ids[0], 'From the inbox action', $owner);
        expect($message->direction)->toBe('out')
            ->and(ChatMessage::query()->where('conversation_id', $ids[0])->where('direction', ChatDirection::Out->value)->count())->toBe(1);
    });
});

it('narrows chats to the member\'s reach and checks each permission', function () {
    ($this->enable)();
    ($this->start)(['text' => 'pool']);
    ($this->start)(['text' => 'someone else\'s']);
    [$pooled, $theirs] = $this->inTenant($this->tenant, fn () => ChatConversation::query()->orderBy('id')->get()->all());
    [$colleague, $colleagueMembership] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply'], ['support.tickets.view' => 'all', 'support.tickets.reply' => 'all']);
    $this->inTenant($this->tenant, fn () => app(LiveChatManager::class)->reply($theirs, 'Mine now', $colleagueMembership));

    // An own-scope agent reaches the unassigned pool, not a colleague's chat.
    [$agent, $agentMembership] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply'], ['support.tickets.view' => 'own', 'support.tickets.reply' => 'own']);
    $this->supportAgent($this->tenant, $agentMembership);

    $this->actingAsMember($this->tenant, $agent)->get(($this->page)('?state=all'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.items', 1)->where('conversations.items.0.id', $pooled->id));
    $this->actingAsMember($this->tenant, $agent)->get(($this->page)('?open='.$theirs->id))->assertNotFound();
    $this->actingAsMember($this->tenant, $agent)->post(($this->page)("/{$theirs->id}/messages"), ['text' => 'Not mine'])->assertNotFound();
    $this->actingAsMember($this->tenant, $agent)->post(($this->page)("/{$pooled->id}/messages"), ['text' => 'Picked up'])->assertRedirect();
    // Closing needs the resolve permission.
    $this->actingAsMember($this->tenant, $agent)->post(($this->page)("/{$pooled->id}/close"))->assertForbidden();

    // Own scope without being an agent: nothing but their own.
    [$plain] = $this->supportMember($this->tenant, ['support.tickets.view'], ['support.tickets.view' => 'own']);
    $this->actingAsMember($this->tenant, $plain)->get(($this->page)('?state=all'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.items', 0));

    // Viewing without replying: no reply, and the page says so.
    [$viewer] = $this->supportMember($this->tenant, ['support.tickets.view'], ['support.tickets.view' => 'all']);
    $this->actingAsMember($this->tenant, $viewer)->get(($this->page)('?open='.$pooled->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.mayReply', false)->where('thread.canReply', false));
    $this->actingAsMember($this->tenant, $viewer)->post(($this->page)("/{$pooled->id}/messages"), ['text' => 'No'])->assertForbidden();

    // Without the ticket view permission the page is forbidden; settings need the settings permission.
    [$outsider] = $this->supportMember($this->tenant, ['support.kb.view']);
    $this->actingAsMember($this->tenant, $outsider)->get(($this->page)())->assertForbidden();
    $this->actingAsMember($this->tenant, $colleague)->get(($this->page)('/settings'))->assertForbidden();

    expect($this->inTenant($this->tenant, fn () => ChatMessage::query()->where('conversation_id', $theirs->id)->count()))->toBe(2);
});

it('pages through the chats newest first without gaps', function () {
    ($this->enable)();
    $ids = $this->inTenant($this->tenant, function (): array {
        $ids = [];

        foreach (range(1, 27) as $minutes) {
            $conversation = ChatConversation::query()->create([
                'visitor_token_hash' => hash('sha256', Str::random(43)), 'status' => ChatStatus::Open,
                'last_message_at' => now()->subMinutes($minutes)->startOfSecond(), 'message_count' => 1,
            ]);
            ChatMessage::query()->create(['conversation_id' => $conversation->id, 'seq' => 1, 'direction' => ChatDirection::In, 'text' => "Chat {$minutes}"]);
            $ids[] = $conversation->id;
        }

        return $ids;
    });

    $next = null;
    $this->actingAsOwner($this->tenant)->get(($this->page)())
        ->assertInertia(function (AssertableInertia $page) use (&$next, $ids) {
            $page->has('conversations.items', 25)
                ->where('conversations.items.0.id', $ids[0])
                ->where('conversations.items.0.preview', 'Chat 1')
                ->where('conversations.previous', null);
            $next = $page->toArray()['props']['conversations']['next'];
        });

    expect($next)->toBeString();

    $this->actingAsOwner($this->tenant)->get(($this->page)('?cursor='.$next))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversations.items', 2)
            ->where('conversations.items.0.id', $ids[25])
            ->where('conversations.items.1.id', $ids[26])
            ->where('conversations.next', null));
});

// ---- settings --------------------------------------------------------------------------------------------------------------

it('shows the settings with the embed snippet and saves them, audited', function () {
    $this->actingAsOwner($this->tenant)->get(($this->page)('/settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Support/Pages/LiveChat/Settings')
            ->where('settings.enabled', false)
            ->where('settings.visitorDetails', 'optional')
            ->where('snippet', '<script src="'.($this->public)('widget.js').'" defer></script>'));

    // Viewing changed nothing.
    expect($this->inTenant($this->tenant, fn () => ChatSetting::query()->count()))->toBe(0);

    $this->actingAsOwner($this->tenant)->put(($this->page)('/settings'), [
        'enabled' => true,
        'allowed_origins' => ['https://WWW.Example.com/', 'https://shop.example.com:443', 'https://www.example.com', 'https://app.example.com:8443'],
        'widget_title' => "  Talk\nto us ",
        'greeting' => 'Hi there',
        'visitor_details' => 'required',
        'offline_note' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $settings = $this->inTenant($this->tenant, fn () => ChatSetting::query()->sole());
    expect($settings->enabled)->toBeTrue()
        ->and($settings->origins())->toBe(['https://www.example.com', 'https://shop.example.com', 'https://app.example.com:8443'])
        ->and($settings->widget_title)->toBe('Talk to us')
        ->and($settings->visitor_details)->toBe(VisitorDetails::Required);

    $audit = $this->auditTrail($this->tenant, 'support.live_chat.settings_saved');
    expect($audit)->toHaveCount(1)
        ->and(json_encode($audit->first()?->properties))->toContain('allowed_origins');
});

it('accepts only canonical https origins, and plain http only for localhost while running locally', function () {
    $put = fn (array $origins, bool $enabled = true) => $this->actingAsOwner($this->tenant)->from(($this->page)('/settings'))->put(($this->page)('/settings'), [
        'enabled' => $enabled, 'allowed_origins' => $origins, 'visitor_details' => 'optional',
    ]);

    foreach (['http://www.example.com', 'http://localhost:5173', 'https://www.example.com/path', 'https://*.example.com', 'https://192.168.1.10',
        'https://user:pass@example.com', 'ftp://example.com', 'example.com', 'https://localhost.', 'https://intranet', 'javascript:alert(1)'] as $bad) {
        $put([$bad])->assertSessionHasErrors('allowed_origins.0');
    }

    $put([])->assertSessionHasErrors('allowed_origins');
    $put(array_map(fn (int $n): string => "https://site{$n}.example.com", range(1, LiveChatLimits::MAX_ORIGINS + 1)))->assertSessionHasErrors('allowed_origins');
    $put([], false)->assertSessionHasNoErrors();

    expect(ChatOrigins::normalize('http://localhost:5173', false))->toBeNull()
        ->and(ChatOrigins::normalize('http://localhost:5173', true))->toBe('http://localhost:5173')
        ->and(ChatOrigins::normalize('http://shop.localhost', true))->toBe('http://shop.localhost')
        ->and(ChatOrigins::normalize('http://www.example.com', true))->toBeNull()
        ->and(ChatOrigins::normalize('HTTPS://Example.COM:443/', false))->toBe('https://example.com');

    // While the application runs locally, a developer can embed it on localhost.
    $this->app['env'] = 'local';
    // Outside `testing` the framework checks the CSRF token, so this request carries it.
    $this->actingAsOwner($this->tenant)->from(($this->page)('/settings'))->put(($this->page)('/settings'), [
        '_token' => session()->token(), 'enabled' => true, 'allowed_origins' => ['http://localhost:5173'], 'visitor_details' => 'optional',
    ])->assertSessionHasNoErrors();
    expect($this->inTenant($this->tenant, fn () => ChatSetting::query()->sole()->origins()))->toBe(['http://localhost:5173']);
});

// ---- isolation --------------------------------------------------------------------------------------------------------------

it('isolates chat settings, conversations and messages between tenants', function () {
    $conversation = fn (): ChatConversation => ChatConversation::query()->create([
        'visitor_token_hash' => hash('sha256', Str::random(43)), 'status' => ChatStatus::Open, 'last_message_at' => now(),
    ]);

    $this->assertTenantIsolated(ChatSetting::class, fn () => ChatSetting::query()->create(['enabled' => false, 'visitor_details' => VisitorDetails::Optional]));
    $this->assertTenantIsolated(ChatConversation::class, $conversation);
    $this->assertTenantIsolated(ChatMessage::class, fn () => ChatMessage::query()->create([
        'conversation_id' => $conversation()->id, 'seq' => 1, 'direction' => ChatDirection::In, 'text' => 'x',
    ]));
});

it('does not open another workspace\'s chat on the Support page', function () {
    ($this->enable)();
    ($this->start)();
    $conversation = ($this->conversation)();

    $this->assertNotFoundFromOtherTenant('GET', '/support/live-chat?open='.$conversation->id, $this->tenant, intruder: $this->supportTenant('sup-chat-intruder'));
});

it('does not answer another workspace\'s chat', function () {
    ($this->enable)();
    ($this->start)();
    $conversation = ($this->conversation)();

    $this->assertNotFoundFromOtherTenant('POST', "/support/live-chat/{$conversation->id}/messages", $this->tenant, ['text' => 'Hello'], $this->supportTenant('sup-chat-intruder'));
});

it('does not close another workspace\'s chat', function () {
    ($this->enable)();
    ($this->start)();
    $conversation = ($this->conversation)();

    $this->assertNotFoundFromOtherTenant('POST', "/support/live-chat/{$conversation->id}/close", $this->tenant, intruder: $this->supportTenant('sup-chat-intruder'));
});
