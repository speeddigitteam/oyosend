<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\InboxSignals;
use App\Core\Realtime\Realtime;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Communication\Realtime\InboxChanged;
use App\Modules\Social\Comments\Data\RemoteComment;
use App\Modules\Social\Comments\Ingest\CommentIngestor;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Messages\Actions\MessageIngestor;
use App\Modules\Social\Messages\Data\MessagingEvent;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Support\Actions\LiveChatManager;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;
use Tests\Support\InteractsWithSupport;
use Tests\Support\InteractsWithWhatsapp;

/*
| Realtime inbox updates (ADR 0035): each source app sends a "conversation changed" signal after its write commits;
| the Communication module broadcasts it on the workspace's private inbox channel; the core route signs a subscription
| only for a member of this hostname's workspace who may read the inbox. Nothing here talks to a real Reverb server.
*/

uses(InteractsWithWhatsapp::class, InteractsWithSupport::class, InteractsWithSocial::class);

const RT_SECRET = 'reverb-secret-for-tests';

/** A broadcaster that remembers what it was asked to send instead of calling a server. */
class InboxRealtimeRecordingBroadcaster extends Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $this->sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
    }
}

beforeEach(function () {
    $this->realtime = function (): void {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'rt-test-key',
            'broadcasting.connections.reverb.secret' => RT_SECRET,
            'broadcasting.connections.reverb.app_id' => '424242',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
            'broadcasting.connections.reverb.client' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
        ]);
    };

    $this->tenant = $this->waTenant('realtime-one');
    $this->entitle($this->tenant, 'support', 'social');
    $this->channel = 'private-tenant.'.$this->tenant->id.'.inbox';
    $this->auth = fn (?string $channel = null, string $socket = '1234.5678'): array => ['socket_id' => $socket, 'channel_name' => $channel ?? $this->channel];
    $this->authUrl = $this->tenantUrl($this->tenant, '/broadcasting/auth');
    $this->viewer = fn (): array => $this->waMember($this->tenant, ['communication.inbox.view']);
    $this->signalled = fn (string $source, string $id): bool => Event::dispatched(InboxChanged::class, fn (InboxChanged $event): bool => $event->source === $source && $event->id === $id && $event->tenantId === $this->tenant->id)->isNotEmpty();
});

// ---- the channel authorization route ------------------------------------------------------------------------------

it('signs the inbox channel for a member who may read the inbox', function () {
    ($this->realtime)();
    [$viewer] = ($this->viewer)();

    $response = $this->actingAsMember($this->tenant, $viewer)->postJson($this->authUrl, ($this->auth)())->assertOk();

    $expected = 'rt-test-key:'.hash_hmac('sha256', '1234.5678:'.$this->channel, RT_SECRET);
    expect($response->json())->toBe(['auth' => $expected]);
});

it('refuses the inbox channel without the inbox permission, for another workspace, and outside a workspace channel', function () {
    ($this->realtime)();
    [$noInbox] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    [$viewer] = ($this->viewer)();
    $other = $this->waTenant('realtime-two');

    $this->actingAsMember($this->tenant, $noInbox)->postJson($this->authUrl, ($this->auth)())->assertForbidden();

    // A member of this workspace asking for another workspace's channel: the hostname decides, not the name.
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $viewer)
        ->postJson($this->authUrl, ($this->auth)('private-tenant.'.$other->id.'.inbox'))->assertForbidden();

    // The owner of the other workspace on their own host, asking for this workspace's channel.
    $this->startNewBrowserSession()->actingAsOwner($other)
        ->postJson($this->tenantUrl($other, '/broadcasting/auth'), ($this->auth)())->assertForbidden();

    foreach (['private-encrypted-tenant.'.$this->tenant->id.'.inbox', 'presence-tenant.'.$this->tenant->id.'.inbox', 'tenant.'.$this->tenant->id.'.inbox', 'private-tenant.'.$this->tenant->id.'.unknown', 'private-other.thing'] as $channel) {
        $this->startNewBrowserSession()->actingAsMember($this->tenant, $viewer)
            ->postJson($this->authUrl, ($this->auth)($channel))->assertForbidden();
    }

    // Malformed input never reaches the signer; JSONP is not offered.
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $viewer)
        ->postJson($this->authUrl, ($this->auth)(null, 'not-a-socket'))->assertUnprocessable();
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $viewer)
        ->postJson($this->authUrl, [...($this->auth)(), 'callback' => 'steal'])->assertUnprocessable();
});

it('refuses the inbox channel to a workspace that is not entitled to Communication', function () {
    ($this->realtime)();
    $plain = $this->createTenant('realtime-plain');

    $this->actingAsOwner($plain)
        ->postJson($this->tenantUrl($plain, '/broadcasting/auth'), ['socket_id' => '1.2', 'channel_name' => 'private-tenant.'.$plain->id.'.inbox'])
        ->assertForbidden();
});

it('needs a signed-in session', function () {
    ($this->realtime)();

    $this->postJson($this->authUrl, ($this->auth)())->assertUnauthorized();
});

it('does not exist while realtime is off, nor on the central or the admin host', function () {
    [$viewer] = ($this->viewer)();

    $this->actingAsMember($this->tenant, $viewer)->postJson($this->authUrl, ($this->auth)())->assertNotFound();

    ($this->realtime)();
    $this->startNewBrowserSession()->postJson($this->centralUrl('/broadcasting/auth'), ($this->auth)())->assertNotFound();
    $this->startNewBrowserSession()->postJson($this->adminUrl('/broadcasting/auth'), ($this->auth)())->assertNotFound();
});

it('stays off unless every setting is present and well formed', function () {
    ($this->realtime)();
    expect(app(Realtime::class)->socketOrigin())->toBe('ws://localhost:8080');

    foreach ([
        ['broadcasting.connections.reverb.secret' => ''],
        ['broadcasting.connections.reverb.key' => null],
        ['broadcasting.connections.reverb.client.host' => 'evil.test; script-src *'],
        ['broadcasting.connections.reverb.client.port' => 'x'],
        ['broadcasting.connections.reverb.client.scheme' => 'ftp'],
        ['broadcasting.default' => 'log'],
    ] as $broken) {
        ($this->realtime)();
        config($broken);

        expect(app(Realtime::class)->client())->toBeNull();
    }
});

// ---- what the page gets ------------------------------------------------------------------------------------------

it('gives the inbox page the public connection details and its channel, never the secret', function () {
    [$viewer] = ($this->viewer)();
    $inbox = $this->tenantUrl($this->tenant, '/communication/inbox');

    $this->actingAsMember($this->tenant, $viewer)->get($inbox)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('realtime', null));

    ($this->realtime)();
    $response = $this->actingAsMember($this->tenant, $viewer)->get($inbox)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('realtime', [
            'key' => 'rt-test-key',
            'host' => 'localhost',
            'port' => 8080,
            'scheme' => 'http',
            'channel' => 'tenant.'.$this->tenant->id.'.inbox',
            'authEndpoint' => '/broadcasting/auth',
        ]));

    expect($response->getContent())->not->toContain(RT_SECRET);
    $policy = (string) ($response->headers->get('Content-Security-Policy') ?? $response->headers->get('Content-Security-Policy-Report-Only'));
    expect($policy)->toContain('ws://localhost:8080');
});

// ---- the signals -------------------------------------------------------------------------------------------------

it('sends nothing while realtime is off', function () {
    Event::fake([InboxChanged::class]);
    $this->waConnectCloud($this->tenant, wamid: 'wamid.OUT-RT');

    $this->waInbound($this->tenant, '+8801711000001', 'Hello')->assertOk();

    Event::assertNotDispatched(InboxChanged::class);
});

it('signals a WhatsApp conversation on an inbound message, a reply and a delivery status, with ids only', function () {
    ($this->realtime)();
    Event::fake([InboxChanged::class]);
    $this->waConnectCloud($this->tenant, wamid: 'wamid.OUT-RT');
    $this->waContact($this->tenant);

    $this->waInbound($this->tenant, '+8801711000001', 'Is the blue one in stock?')->assertOk();
    $conversation = $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->sole());

    expect(($this->signalled)('whatsapp_marketing.conversations', $conversation->id))->toBeTrue();
    $event = Event::dispatched(InboxChanged::class)->first()[0];
    expect($event->broadcastWith())->toBe(['source' => 'whatsapp_marketing.conversations', 'id' => $conversation->id])
        ->and($event->broadcastAs())->toBe('inbox.changed')
        ->and($event->broadcastOn()->name)->toBe($this->channel)
        ->and($event->queue)->toBe('notifications');

    Event::fake([InboxChanged::class]);
    [$agent] = $this->waMember($this->tenant, ['communication.inbox.view', 'whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply']);
    $this->actingAsMember($this->tenant, $agent)
        ->post($this->tenantUrl($this->tenant, '/communication/inbox/whatsapp_marketing.conversations/'.$conversation->id.'/reply'), ['text' => 'Yes, it is.'])
        ->assertSessionHasNoErrors();
    expect(($this->signalled)('whatsapp_marketing.conversations', $conversation->id))->toBeTrue();

    Event::fake([InboxChanged::class]);
    $this->waStatus($this->tenant, 'wamid.OUT-RT', 'delivered')->assertOk();
    expect(($this->signalled)('whatsapp_marketing.conversations', $conversation->id))->toBeTrue();
});

it('signals a Messenger conversation when a message arrives', function () {
    ($this->realtime)();
    Event::fake([InboxChanged::class]);
    $account = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => '1000', 'name' => 'Brand Page', 'token_expires_at' => null]);

    $this->inTenant($this->tenant, fn () => app(MessageIngestor::class)->apply($account, new MessagingEvent(
        platform: 'messenger', entryId: '1000', kind: MessagingEvent::MESSAGE, participantId: '5551234',
        at: CarbonImmutable::now(), mid: 'm_rt_1', text: 'Hello there',
    )));

    $conversation = $this->inTenant($this->tenant, fn (): DirectConversation => DirectConversation::query()->sole());
    expect(($this->signalled)('social.messenger', $conversation->id))->toBeTrue();
});

it('signals a Facebook comment thread by its top-level comment', function () {
    ($this->realtime)();
    Event::fake([InboxChanged::class]);
    config(['social_comments.enabled' => true]);
    $account = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => '1000', 'name' => 'Brand Page', 'token_expires_at' => null]);
    $remote = fn (string $id, ?string $parent): RemoteComment => new RemoteComment(id: $id, postId: '1000_post1', parentId: $parent, authorId: '777', authorName: 'Nadia', message: 'Price?', createdAt: CarbonImmutable::now());

    [$top] = $this->inTenant($this->tenant, fn (): array => app(CommentIngestor::class)->store($account, $remote('1000_c1', null), 'webhook'));
    $this->inTenant($this->tenant, fn (): array => app(CommentIngestor::class)->store($account, $remote('1000_c2', '1000_c1'), 'webhook'));
    [$deep] = $this->inTenant($this->tenant, fn (): array => app(CommentIngestor::class)->store($account, $remote('1000_c3', '1000_c2'), 'webhook'));

    expect($top)->toBeInstanceOf(AudienceComment::class)
        ->and($deep->parent_id)->not->toBe($top->id);
    expect(Event::dispatched(InboxChanged::class)->map(fn (array $args): string => $args[0]->source.':'.$args[0]->id)->unique()->values()->all())
        ->toBe(['social.comments:'.$top->id]);
});

it('signals a website chat on every message and a ticket on public messages only', function () {
    ($this->realtime)();
    Event::fake([InboxChanged::class]);
    $owner = $this->supportOwnerAgent($this->tenant);

    $chat = $this->inTenant($this->tenant, fn (): array => app(LiveChatManager::class)->start('Hi, is anyone there?', 'Visitor', null, null));
    expect(($this->signalled)('support.live_chat', $chat['conversation']->id))->toBeTrue();

    Event::fake([InboxChanged::class]);
    $this->inTenant($this->tenant, fn (): ChatMessage => app(LiveChatManager::class)->reply($chat['conversation'], 'Hello!', $owner));
    expect(($this->signalled)('support.live_chat', $chat['conversation']->id))->toBeTrue();

    $ticket = $this->supportTicket($this->tenant);
    Event::fake([InboxChanged::class]);
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->addMessage($ticket, MessageKind::InternalNote, 'Customer is a VIP.', $owner));
    Event::assertNotDispatched(InboxChanged::class);

    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->addMessage($ticket, MessageKind::PublicReply, 'We are on it.', $owner));
    expect(($this->signalled)('support.tickets', $ticket->id))->toBeTrue();
});

it('sends nothing for a write that is rolled back', function () {
    ($this->realtime)();
    Event::fake([InboxChanged::class]);

    $this->inTenant($this->tenant, function (): void {
        try {
            DB::transaction(function (): void {
                app(LiveChatManager::class)->start('Rolled back', null, null, null);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // expected
        }
    });

    Event::assertNotDispatched(InboxChanged::class);
    expect($this->inTenant($this->tenant, fn (): int => ChatConversation::query()->count()))->toBe(0);
});

it('broadcasts only the source and the id on the workspace channel', function () {
    ($this->realtime)();
    $recorder = new InboxRealtimeRecordingBroadcaster;
    Broadcast::extend('reverb', fn () => $recorder);

    $id = (string) Str::ulid();
    $this->inTenant($this->tenant, fn () => app(InboxSignals::class)->changed('support.live_chat', $id));

    expect($recorder->sent)->toHaveCount(1)
        ->and($recorder->sent[0]['channels'])->toBe([$this->channel])
        ->and($recorder->sent[0]['event'])->toBe('inbox.changed')
        // The framework adds `socket` (the sender's socket id to skip, none here).
        ->and($recorder->sent[0]['payload'])->toBe(['source' => 'support.live_chat', 'id' => $id, 'socket' => null]);
});

it('keeps the write when the realtime server fails', function () {
    ($this->realtime)();
    Broadcast::extend('reverb', fn () => new class extends InboxRealtimeRecordingBroadcaster
    {
        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new BroadcastException('Pusher error: connection refused to http://127.0.0.1:8080/apps?auth_signature=secret');
        }
    });
    Log::spy();

    $chat = $this->inTenant($this->tenant, fn (): array => app(LiveChatManager::class)->start('Still stored', null, null, null));

    expect($this->inTenant($this->tenant, fn (): int => ChatMessage::query()->where('conversation_id', $chat['conversation']->id)->count()))->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['exception' => BroadcastException::class, 'source' => 'support.live_chat']);
});

it('queues the broadcast on the notifications queue as tenant-bound work', function () {
    ($this->realtime)();
    Queue::fake();
    $id = (string) Str::ulid();

    $this->inTenant($this->tenant, fn () => app(InboxSignals::class)->changed('support.tickets', $id));

    Queue::assertPushedOn('notifications', BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof InboxChanged
        && $job->event->tenantId === $this->tenant->id && $job->event->id === $id);
    expect(new InboxChanged('t', 's', 'i'))->toBeInstanceOf(RequiresTenantContext::class);
});
