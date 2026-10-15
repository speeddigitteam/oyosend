<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Modules\Communication\Actions\UnifiedInbox;
use App\Modules\Communication\Models\Message as OutboxMessage;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Message as SupportMessage;
use App\Modules\Support\Models\Ticket;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;
use Tests\Support\InteractsWithSupport;
use Tests\Support\InteractsWithWhatsapp;

/*
| The unified inbox as a chat workspace (ADR 0030): a conversation opens beside the list and is answered from there,
| always through the owning app's own action (WhatsApp Messenger, Social CommentActions).
*/

uses(InteractsWithWhatsapp::class, InteractsWithSupport::class, InteractsWithSocial::class);

beforeEach(function () {
    config([
        'social.platforms.facebook.client_id' => 'app-id',
        'social.platforms.facebook.client_secret' => 'app-secret-for-tests',
        'social_comments.enabled' => true,
    ]);
    // Registered before the WhatsApp fake so that Facebook comment calls get a comment id, not a message id.
    Http::fake(['graph.facebook.com/*/comments' => Http::response(['id' => '1000_reply1'])]);

    $this->tenant = $this->waTenant('inbox-chat');
    $this->entitle($this->tenant, 'support', 'social');
    $this->waConnectCloud($this->tenant, wamid: 'wamid.OUT-CHAT');
    $this->contact = $this->waContact($this->tenant);
    $this->waInbound($this->tenant, '+8801711000001', 'Is the blue one in stock?')->assertOk();
    $this->conversation = $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->sole());

    $this->inbox = fn (string $query = ''): string => $this->tenantUrl($this->tenant, '/communication/inbox'.$query);
    $this->openUrl = fn (string $source, string $id): string => ($this->inbox)('?open='.$source.':'.$id);
    $this->replyUrl = fn (string $source, string $id): string => $this->tenantUrl($this->tenant, "/communication/inbox/{$source}/{$id}/reply");
    $this->readUrl = fn (string $source, string $id): string => $this->tenantUrl($this->tenant, "/communication/inbox/{$source}/{$id}/read");
    $this->member = fn (array $permissions, array $scopes = []): array => $this->waMember($this->tenant, ['communication.inbox.view', ...$permissions], $scopes);
    $this->outbound = fn (): int => $this->inTenant($this->tenant, fn (): int => Message::query()->where('direction', Direction::Outbound->value)->count());

    $this->page = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => '1000', 'name' => 'Brand Page', 'token_expires_at' => null]);
    $this->comment = fn (array $attributes = []): AudienceComment => $this->inTenant($this->tenant, fn (): AudienceComment => AudienceComment::query()->create([
        'account_id' => $this->page->id,
        'platform' => 'facebook',
        'external_id' => '1000_'.Str::lower(Str::random(8)),
        'external_post_id' => '1000_post1',
        'author_external_id' => '777',
        'author_name' => 'Nadia Karim',
        'message' => 'What is the price?',
        'remote_state' => RemoteState::Visible,
        'source' => 'webhook',
        'commented_at' => now()->subMinutes(30)->startOfSecond(),
        'received_at' => now()->subMinutes(30)->startOfSecond(),
        ...$attributes,
    ]));
});

// ---- WhatsApp -------------------------------------------------------------------------------------------------------

it('opens a WhatsApp conversation beside the list for a member who may see it', function () {
    [$agent] = ($this->member)(['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply']);

    $this->actingAsMember($this->tenant, $agent)
        ->get(($this->openUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Communication/Pages/Inbox/Index')
            ->where('filters.open', 'whatsapp_marketing.conversations:'.$this->conversation->id)
            ->where('thread.key', 'whatsapp_marketing.conversations:'.$this->conversation->id)
            ->where('thread.sourceLabel', 'WhatsApp')
            ->where('thread.title', 'Rahim Uddin')
            ->where('thread.subtitle', '+8801711000001')
            ->where('thread.contactId', $this->contact->id)
            ->where('thread.url', fn (string $url) => str_ends_with($url, '/whatsapp/inbox/'.$this->conversation->id))
            ->where('thread.replyAllowed', true)
            ->where('thread.canReply', true)
            ->where('thread.replyBlockedReason', null)
            ->where('thread.hasOlder', false)
            ->has('thread.messages', 1)
            ->where('thread.messages.0.direction', 'in')
            ->where('thread.messages.0.text', 'Is the blue one in stock?')
            ->where('thread.messages.0.status', null)
            ->where('list.items.0.chat', true));
});

it('shows nothing of another workspace\'s conversation and answers 404 to a reply', function () {
    $intruder = $this->waTenant('inbox-chat-intruder');
    $this->entitle($intruder, 'social');
    // Making a workspace resets the operator credentials; the home workspace's channel needs them for its control.
    $this->waConnectCloud($this->tenant, wamid: 'wamid.OUT-CHAT');
    $comment = ($this->comment)();

    foreach (['whatsapp_marketing.conversations:'.$this->conversation->id, 'social.comments:'.$comment->id] as $open) {
        // The reading pane is simply empty: the same answer as for a conversation that does not exist.
        $this->startNewBrowserSession()->actingAsOwner($intruder)
            ->get($this->tenantUrl($intruder, '/communication/inbox?open='.$open))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.open', $open)->where('thread', null));
        $this->startNewBrowserSession()->actingAsOwner($this->tenant)
            ->get(($this->inbox)('?open='.$open))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.key', $open));
    }

    $this->assertNotFoundFromOtherTenant('POST', "/communication/inbox/whatsapp_marketing.conversations/{$this->conversation->id}/reply", $this->tenant, ['text' => 'Hello'], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', "/communication/inbox/social.comments/{$comment->id}/reply", $this->tenant, ['text' => 'Hello'], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', "/communication/inbox/whatsapp_marketing.conversations/{$this->conversation->id}/read", $this->tenant, [], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', "/communication/inbox/social.comments/{$comment->id}/read", $this->tenant, [], $intruder);

    // Only the home workspace's own control replies went out.
    expect(($this->outbound)())->toBe(1);
    $this->inTenant($intruder, fn () => expect(Message::query()->count())->toBe(0)->and(AudienceComment::query()->count())->toBe(0));
});

it('does not disclose a conversation outside the member\'s record scope, nor mark it read', function () {
    [$ownScoped] = ($this->member)(
        ['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply'],
        ['whatsapp_marketing.conversations.view' => 'own', 'whatsapp_marketing.conversations.reply' => 'own'],
    );

    $this->actingAsMember($this->tenant, $ownScoped)
        ->get(($this->openUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread', null));
    $this->actingAsMember($this->tenant, $ownScoped)
        ->post(($this->replyUrl)('whatsapp_marketing.conversations', $this->conversation->id), ['text' => 'Hello'])
        ->assertNotFound();
    $this->actingAsMember($this->tenant, $ownScoped)
        ->postJson(($this->readUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertNotFound();

    Http::assertNothingSent();
    expect(($this->outbound)())->toBe(0);
    $this->inTenant($this->tenant, fn () => expect(Conversation::query()->sole()->unread_count)->toBe(1));
});

it('never marks read on loading the page or a poll, only on the explicit POST, as the WhatsApp page does', function () {
    [$agent] = ($this->member)(['whatsapp_marketing.conversations.view']);
    $open = ($this->openUrl)('whatsapp_marketing.conversations', $this->conversation->id);
    $unread = fn (): int => $this->inTenant($this->tenant, fn (): int => Conversation::query()->sole()->unread_count);
    $before = $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->sole());
    expect($before->unread_count)->toBe(1);

    // A GET (a link someone followed, or the page itself) changes nothing.
    $first = $this->actingAsMember($this->tenant, $agent)->get($open)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.marksRead', true)
            ->where('list.items.0.key', 'whatsapp_marketing.conversations:'.$this->conversation->id)
            ->where('list.items.0.unread', 1));
    expect($unread())->toBe(1);

    // The page's background POST (JSON) answers 204.
    $this->actingAsMember($this->tenant, $agent)
        ->postJson(($this->readUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertNoContent();

    $after = $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->sole());
    // Exactly what ConversationManager::markRead() does for the WhatsApp page: the unread count, nothing else.
    expect($after->unread_count)->toBe(0)
        ->and($after->owner_id)->toBe($before->owner_id)
        ->and($after->status)->toBe($before->status)
        ->and($after->last_message_at?->toIso8601String())->toBe($before->last_message_at?->toIso8601String());

    // A new message arrives while the thread is open: the poll (a partial reload of `thread`) shows it, unread stays.
    $this->waInbound($this->tenant, '+8801711000001', 'Hello again?')->assertOk();
    $poll = $this->actingAsMember($this->tenant, $agent)->get($open, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $first->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Modules/Communication/Pages/Inbox/Index',
        'X-Inertia-Partial-Data' => 'thread',
    ])->assertOk();

    expect($poll->json('props.thread.messages'))->toHaveCount(2)
        ->and($poll->json('props'))->not->toHaveKey('list')
        ->and($unread())->toBe(1);

    // A plain form post is redirected back.
    $this->actingAsMember($this->tenant, $agent)->from($open)
        ->post(($this->readUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertRedirect($open);
    expect($unread())->toBe(0);
});

it('lets only inbox members who may see the conversation mark it read', function () {
    [$noInbox] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    [$noSource] = ($this->member)([]);

    $this->actingAsMember($this->tenant, $noInbox)
        ->postJson(($this->readUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertForbidden();
    $this->actingAsMember($this->tenant, $noSource)
        ->postJson(($this->readUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertNotFound();

    $this->inTenant($this->tenant, fn () => expect(Conversation::query()->sole()->unread_count)->toBe(1));
});

it('answers a poll with no thread, not an error page, when the conversation is no longer the member\'s', function () {
    [$agentUser, $agent] = ($this->member)(
        ['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply'],
        ['whatsapp_marketing.conversations.view' => 'own', 'whatsapp_marketing.conversations.reply' => 'own'],
    );
    $this->inTenant($this->tenant, fn () => $this->conversation->assignTo($agent)->save());
    $open = ($this->openUrl)('whatsapp_marketing.conversations', $this->conversation->id);

    $first = $this->actingAsMember($this->tenant, $agentUser)->get($open)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.id', $this->conversation->id));

    // Handed to someone else: outside an own-scoped member's reach from now on.
    $this->inTenant($this->tenant, fn () => $this->conversation->assignTo($this->membershipOf($this->tenant, $this->ownerOf($this->tenant)))->save());

    $this->actingAsMember($this->tenant, $agentUser)->get($open, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $first->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Modules/Communication/Pages/Inbox/Index',
        'X-Inertia-Partial-Data' => 'thread',
    ])->assertOk()->assertJsonPath('component', 'Modules/Communication/Pages/Inbox/Index')->assertJsonPath('props.thread', null);

    $this->actingAsMember($this->tenant, $agentUser)->get($open)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.open', 'whatsapp_marketing.conversations:'.$this->conversation->id)->where('thread', null));
    $this->actingAsMember($this->tenant, $agentUser)
        ->post(($this->replyUrl)('whatsapp_marketing.conversations', $this->conversation->id), ['text' => 'Hello'])
        ->assertNotFound();
    Http::assertNothingSent();
});

it('replies inside the 24-hour window through the WhatsApp app, exactly once', function () {
    [$agent, $membership] = ($this->member)(['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply']);
    $open = ($this->openUrl)('whatsapp_marketing.conversations', $this->conversation->id);

    $this->actingAsMember($this->tenant, $agent)
        ->from($open)
        ->post(($this->replyUrl)('whatsapp_marketing.conversations', $this->conversation->id), ['text' => 'Yes, it is in stock.'])
        ->assertRedirect($open)
        ->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['type'] === 'text'
        && $request['text']['body'] === 'Yes, it is in stock.'
        && $request['to'] === '8801711000001');

    $this->inTenant($this->tenant, function () use ($membership): void {
        $reply = Message::query()->where('direction', Direction::Outbound->value)->sole();
        $outbox = OutboxMessage::query()->sole();

        expect($reply->body)->toBe('Yes, it is in stock.')
            ->and($reply->author_id)->toBe($membership->id)
            ->and($outbox->purpose->value)->toBe('transactional')
            ->and($outbox->meta['whatsapp_window_until'])->toBeString()
            // The app's own rule: a person answered, so the unassigned conversation is now theirs.
            ->and(Conversation::query()->sole()->owner_id)->toBe($membership->id);
    });

    $this->actingAsMember($this->tenant, $agent)
        ->get($open)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('thread.messages', 2)
            ->where('thread.messages.1.direction', 'out')
            ->where('thread.messages.1.text', 'Yes, it is in stock.')
            ->where('thread.messages.1.author', $agent->name)
            ->where('thread.messages.1.status', 'sent'));
});

it('refuses a free-form reply once the window has closed, records nothing and says why', function () {
    [$agent] = ($this->member)(['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply']);
    $this->travel(25)->hours();
    $open = ($this->openUrl)('whatsapp_marketing.conversations', $this->conversation->id);

    $this->actingAsMember($this->tenant, $agent)
        ->get($open)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.replyAllowed', true)
            ->where('thread.canReply', false)
            ->where('thread.replyBlockedReason', fn (string $reason) => str_contains($reason, '24-hour window')));

    $this->actingAsMember($this->tenant, $agent)
        ->from($open)
        ->post(($this->replyUrl)('whatsapp_marketing.conversations', $this->conversation->id), ['text' => 'Are you still there?'])
        ->assertRedirect($open)
        ->assertSessionHasErrors('text');

    Http::assertNothingSent();
    expect(($this->outbound)())->toBe(0);
    $this->inTenant($this->tenant, fn () => expect(OutboxMessage::query()->count())->toBe(0));
});

it('shows no composer and forbids a reply without the source\'s reply permission', function () {
    [$viewer] = ($this->member)(['whatsapp_marketing.conversations.view']);

    $this->actingAsMember($this->tenant, $viewer)
        ->get(($this->openUrl)('whatsapp_marketing.conversations', $this->conversation->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.replyAllowed', false)
            ->where('thread.canReply', false)
            ->where('thread.replyBlockedReason', null));

    $this->actingAsMember($this->tenant, $viewer)
        ->post(($this->replyUrl)('whatsapp_marketing.conversations', $this->conversation->id), ['text' => 'Hello'])
        ->assertForbidden();

    Http::assertNothingSent();
    expect(($this->outbound)())->toBe(0);
});

it('validates the reply text before anything else', function () {
    $this->actingAsOwner($this->tenant)
        ->post(($this->replyUrl)('whatsapp_marketing.conversations', $this->conversation->id), ['text' => str_repeat('a', 4097)])
        ->assertSessionHasErrors('text');
    $this->actingAsOwner($this->tenant)
        ->get(($this->inbox)('?open=not-a-conversation'))
        ->assertSessionHasErrors('open');

    Http::assertNothingSent();
});

// ---- Facebook comments ----------------------------------------------------------------------------------------------

it('reads a Facebook comment thread and replies to its newest unanswered comment through the Social app', function () {
    $root = ($this->comment)(['external_id' => '1000_c1', 'replied_at' => now()->subMinutes(20), 'handled_at' => now()->subMinutes(20)]);
    ($this->comment)(['external_id' => '1000_p1', 'parent_id' => $root->id, 'parent_external_id' => '1000_c1', 'is_from_page' => true, 'author_external_id' => '1000', 'author_name' => 'Brand Page', 'message' => 'It is 500 BDT.', 'handled_at' => now()->subMinutes(20), 'commented_at' => now()->subMinutes(20)->startOfSecond()]);
    $followUp = ($this->comment)(['external_id' => '1000_c2', 'parent_id' => $root->id, 'parent_external_id' => '1000_c1', 'message' => 'Do you deliver to Sylhet?', 'commented_at' => now()->subMinutes(5)->startOfSecond()]);
    [$agent] = ($this->member)(['social.comments.view', 'social.comments.reply']);
    $open = ($this->openUrl)('social.comments', $root->id);

    $this->actingAsMember($this->tenant, $agent)
        ->get(($this->inbox)('?source=social.comments'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['unassigned' => 1, 'active' => 0, 'closed' => 0])
            ->has('list.items', 1)
            ->where('list.items.0.key', 'social.comments:'.$root->id)
            ->where('list.items.0.title', 'Nadia Karim on Brand Page')
            ->where('list.items.0.preview', 'Do you deliver to Sylhet?')
            ->where('list.items.0.state', 'unassigned')
            ->where('list.items.0.unread', 1)
            ->where('list.items.0.chat', true));

    // Search reaches the replies of a thread and the commenter's name.
    $this->actingAsMember($this->tenant, $agent)->get(($this->inbox)('?source=social.comments&search=Sylhet'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('list.items', 1));
    $this->actingAsMember($this->tenant, $agent)->get(($this->inbox)('?source=social.comments&search=Nadia'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('list.items', 1));
    $this->actingAsMember($this->tenant, $agent)->get(($this->inbox)('?source=social.comments&search=Chittagong'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('list.items', 0)->where('counts', ['unassigned' => 0, 'active' => 0, 'closed' => 0]));

    $this->actingAsMember($this->tenant, $agent)
        ->get($open)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.sourceLabel', 'Facebook comments')
            ->where('thread.channel', 'facebook')
            // No unread state to clear: opening the thread leaves it awaiting an answer.
            ->where('thread.marksRead', false)
            ->where('list.items', fn (Collection $items) => $items->firstWhere('source', 'social.comments')['unread'] === 1)
            ->where('thread.canReply', true)
            ->where('thread.url', fn (string $url) => str_contains($url, '/social/comments?comment='.$root->id))
            ->has('thread.messages', 3)
            ->where('thread.messages.0.direction', 'in')
            ->where('thread.messages.1.direction', 'out')
            ->where('thread.messages.1.text', 'It is 500 BDT.')
            ->where('thread.messages.2.text', 'Do you deliver to Sylhet?'));

    // Mark-read is a no-op for a source without an unread state: the thread still awaits its answer.
    $this->actingAsMember($this->tenant, $agent)->postJson(($this->readUrl)('social.comments', $root->id))->assertNoContent();

    $this->inTenant($this->tenant, function () use ($root, $followUp): void {
        $untouched = AudienceComment::query()->findOrFail($followUp->id);

        expect($untouched->read_at)->toBeNull()->and($untouched->handled_at)->toBeNull()
            ->and(AudienceComment::query()->findOrFail($root->id)->read_at)->toBeNull();
    });

    $this->actingAsMember($this->tenant, $agent)
        ->from($open)
        ->post(($this->replyUrl)('social.comments', $root->id), ['text' => 'Yes, in two days.'])
        ->assertRedirect($open)
        ->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/1000_c2/comments') && $request['message'] === 'Yes, in two days.');

    $this->inTenant($this->tenant, function () use ($followUp): void {
        $stored = AudienceComment::query()->where('external_id', '1000_reply1')->sole();

        expect($stored->is_from_page)->toBeTrue()
            ->and($stored->parent_id)->toBe($followUp->id)
            ->and(AudienceComment::query()->findOrFail($followUp->id)->handled_at)->not->toBeNull()
            ->and(AuditLog::query()->where('event', 'social.comment.replied')->count())->toBe(1);
    });

    // The thread is answered: the reply shows last and the conversation leaves the open list.
    $this->actingAsMember($this->tenant, $agent)
        ->get($open.'&state=all')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('thread.messages', 4)
            ->where('thread.messages.3.direction', 'out')
            ->where('thread.messages.3.text', 'Yes, in two days.')
            ->where('list.items', fn (Collection $items) => $items->firstWhere('source', 'social.comments')['state'] === 'closed'
                && $items->firstWhere('source', 'social.comments')['preview'] === 'Yes, in two days.'));
});

it('narrows Facebook threads to the member\'s scope and refuses a reply to a deleted comment with a reason', function () {
    $mine = ($this->comment)(['external_id' => '1000_m1']);
    $other = ($this->comment)(['external_id' => '1000_o1']);
    [$agent, $membership] = ($this->member)(['social.comments.view', 'social.comments.reply'], ['social.comments.view' => 'own', 'social.comments.reply' => 'own']);
    $this->inTenant($this->tenant, fn () => $mine->assignOwner($membership)->save());

    $this->actingAsMember($this->tenant, $agent)
        ->get(($this->inbox)('?source=social.comments'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['unassigned' => 0, 'active' => 1, 'closed' => 0])
            ->has('list.items', 1)
            ->where('list.items.0.key', 'social.comments:'.$mine->id)
            ->where('list.items.0.state', 'active'));
    $this->actingAsMember($this->tenant, $agent)->get(($this->openUrl)('social.comments', $other->id))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('thread', null));
    $this->actingAsMember($this->tenant, $agent)->post(($this->replyUrl)('social.comments', $other->id), ['text' => 'Hi'])->assertNotFound();

    $this->inTenant($this->tenant, fn () => $mine->forceFill(['remote_state' => RemoteState::Deleted])->save());
    $open = ($this->openUrl)('social.comments', $mine->id);

    $this->actingAsMember($this->tenant, $agent)
        ->get($open)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.canReply', false)
            ->where('thread.replyBlockedReason', 'This comment was deleted.')
            ->where('thread.messages.0.status', 'deleted'));
    $this->actingAsMember($this->tenant, $agent)
        ->from($open)
        ->post(($this->replyUrl)('social.comments', $mine->id), ['text' => 'Hi'])
        ->assertSessionHasErrors(['text' => 'This comment was deleted.']);

    Http::assertNothingSent();
});

it('orders Facebook threads by their newest comment and pages through them without gaps', function () {
    $old = ($this->comment)(['external_id' => '1000_a', 'message' => 'Old thread', 'commented_at' => now()->subHours(5)->startOfSecond()]);
    ($this->comment)(['external_id' => '1000_b', 'message' => 'Middle thread', 'commented_at' => now()->subHours(3)->startOfSecond()]);
    ($this->comment)(['external_id' => '1000_c', 'message' => 'Newer thread', 'commented_at' => now()->subHours(2)->startOfSecond()]);
    // A follow-up under the oldest thread brings it to the top.
    ($this->comment)(['external_id' => '1000_a2', 'parent_id' => $old->id, 'message' => 'Any news?', 'commented_at' => now()->subHour()->startOfSecond()]);
    $owner = $this->ownerOf($this->tenant);
    $membership = $this->membershipOf($this->tenant, $owner);

    $pages = $this->inTenant($this->tenant, function () use ($owner, $membership): array {
        $inbox = app(UnifiedInbox::class);
        $sources = ['social.comments' => $inbox->availableFor($owner)['social.comments']];
        $query = new InboxQuery($owner, $membership->id, InboxState::cases(), limit: 2);
        $seen = [];
        $after = null;

        do {
            $page = $inbox->page($sources, $query, $after);
            $seen[] = array_map(fn (array $entry): ?string => $entry['item']->preview, $page['items']);
            $after = $page['next'];
        } while ($after !== null);

        return $seen;
    });

    expect($pages)->toBe([['Any news?', 'Newer thread'], ['Middle thread']]);
});

// ---- sources without chat, entitlement, the list -------------------------------------------------------------------

// ---- Support tickets -----------------------------------------------------------------------------------------------

it('opens a support ticket as a chat without its internal notes, and answers it with a public reply', function () {
    $ticket = $this->supportTicket($this->tenant, ['subject' => 'Refund please', 'body' => 'Where is my refund?']);
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->addMessage($ticket, MessageKind::InternalNote, 'Check the bank first.', null));
    [$agent, $membership] = ($this->member)(['support.tickets.view', 'support.tickets.reply']);
    $open = ($this->openUrl)('support.tickets', $ticket->id);

    $this->actingAsMember($this->tenant, $agent)
        ->get($open)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.key', 'support.tickets:'.$ticket->id)
            ->where('thread.title', 'Nadia Karim')
            ->where('thread.subtitle', $ticket->number.' · Refund please')
            ->where('thread.url', fn (string $url) => str_ends_with($url, '/support/tickets/'.$ticket->id))
            ->where('thread.canReply', true)
            ->where('thread.marksRead', false)
            ->has('thread.messages', 1)
            ->where('thread.messages.0.direction', 'in')
            ->where('thread.messages.0.text', 'Where is my refund?'));

    $this->actingAsMember($this->tenant, $agent)
        ->from($open)
        ->post(($this->replyUrl)('support.tickets', $ticket->id), ['text' => 'We sent it today.'])
        ->assertRedirect($open)
        ->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function () use ($ticket, $membership): void {
        $reply = SupportMessage::query()->where('ticket_id', $ticket->id)->where('kind', MessageKind::PublicReply->value)->sole();
        $fresh = Ticket::query()->findOrFail($ticket->id);

        expect($reply->body)->toBe('We sent it today.')
            ->and($reply->author_id)->toBe($membership->id)
            ->and($fresh->last_agent_message_at)->not->toBeNull()
            ->and($fresh->first_responded_at)->not->toBeNull();
    });

    $this->actingAsMember($this->tenant, $agent)
        ->get($open)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('thread.messages', 2)
            ->where('thread.messages.1.direction', 'out')
            ->where('thread.messages.1.text', 'We sent it today.')
            ->where('thread.messages.1.status', 'sent'));

    // A ticket has no unread state to clear: the read POST is accepted and changes nothing.
    $this->actingAsMember($this->tenant, $agent)->postJson(($this->readUrl)('support.tickets', $ticket->id))->assertNoContent();
    // Only the inbox's own sources answer.
    $this->actingAsOwner($this->tenant)->post(($this->replyUrl)('crm.leads', $ticket->id), ['text' => 'Hello'])->assertNotFound();
});

it('explains why a closed or merged ticket cannot be answered, and refuses the reply on the composer\'s field', function (TicketStatus $status, string $reason) {
    $ticket = $this->supportTicket($this->tenant, ['subject' => 'Refund please']);
    $this->inTenant($this->tenant, fn () => $ticket->forceFill(['status' => $status])->save());
    $open = ($this->openUrl)('support.tickets', $ticket->id);

    $this->actingAsOwner($this->tenant)->get($open)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canReply', false)->where('thread.replyBlockedReason', $reason));
    $this->actingAsOwner($this->tenant)->from($open)
        ->post(($this->replyUrl)('support.tickets', $ticket->id), ['text' => 'Hello'])
        ->assertSessionHasErrors(['text' => $reason]);

    $this->inTenant($this->tenant, fn () => expect(SupportMessage::query()->where('kind', MessageKind::PublicReply->value)->count())->toBe(0));
})->with([
    'closed' => [TicketStatus::Closed, 'Reopen the ticket before replying to the customer.'],
    'merged' => [TicketStatus::Merged, 'This ticket was merged into another ticket and can no longer be changed.'],
]);

it('lets a member who may only view a ticket read it but not answer it', function () {
    $ticket = $this->supportTicket($this->tenant, ['subject' => 'Refund please']);
    [$viewer] = ($this->member)(['support.tickets.view']);

    $this->actingAsMember($this->tenant, $viewer)->get(($this->openUrl)('support.tickets', $ticket->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.replyAllowed', false)->where('thread.canReply', false)->has('thread.messages', 1));
    $this->actingAsMember($this->tenant, $viewer)->post(($this->replyUrl)('support.tickets', $ticket->id), ['text' => 'Hello'])->assertForbidden();

    $this->inTenant($this->tenant, fn () => expect(SupportMessage::query()->where('kind', MessageKind::PublicReply->value)->count())->toBe(0));
});

it('shows nothing of another workspace\'s ticket and answers 404 to a reply', function () {
    $ticket = $this->supportTicket($this->tenant, ['subject' => 'Refund please']);
    $intruder = $this->waTenant('inbox-chat-ticket-intruder');
    $this->entitle($intruder, 'support');

    $this->actingAsOwner($intruder)->get($this->tenantUrl($intruder, '/communication/inbox?open=support.tickets:'.$ticket->id))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('thread', null));
    $this->assertNotFoundFromOtherTenant('POST', "/communication/inbox/support.tickets/{$ticket->id}/reply", $this->tenant, ['text' => 'Hello'], $intruder);

    // Only the home workspace's own control reply was written.
    $this->inTenant($this->tenant, fn () => expect(SupportMessage::query()->where('kind', MessageKind::PublicReply->value)->count())->toBe(1));
    $this->inTenant($intruder, fn () => expect(SupportMessage::query()->count())->toBe(0));
});

it('marks which listed conversations can be answered here', function () {
    $ticket = $this->supportTicket($this->tenant, ['subject' => 'Refund please']);
    $this->inTenant($this->tenant, fn () => $ticket->forceFill(['last_activity_at' => now()->subHour()])->save());
    ($this->comment)();

    $this->actingAsOwner($this->tenant)
        ->get(($this->inbox)('?state=all'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread', null)
            ->where('list.items', fn (Collection $items) => $items->mapWithKeys(fn (array $item): array => [$item['source'] => $item['chat']])->sortKeys()->all() === [
                'social.comments' => true,
                'support.tickets' => true,
                'whatsapp_marketing.conversations' => true,
            ]));
});

it('leaves out the source of an app the workspace is not entitled to', function () {
    $plain = $this->waTenant('inbox-chat-no-social');
    $account = $this->socialAccount($plain, ['platform' => 'facebook', 'external_id' => '2000', 'name' => 'Other Page', 'token_expires_at' => null]);
    $comment = $this->inTenant($plain, fn (): AudienceComment => AudienceComment::query()->create([
        'account_id' => $account->id, 'platform' => 'facebook', 'external_id' => '2000_c1', 'author_name' => 'Karim',
        'message' => 'Hello?', 'remote_state' => RemoteState::Visible, 'source' => 'webhook', 'commented_at' => now(), 'received_at' => now(),
    ]));

    $this->actingAsOwner($plain)
        ->get($this->tenantUrl($plain, '/communication/inbox?state=all'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn (Collection $sources) => ! $sources->contains('key', 'social.comments'))
            ->where('list.items', fn (Collection $items) => ! $items->contains('source', 'social.comments')));
    $this->actingAsOwner($plain)->get($this->tenantUrl($plain, '/communication/inbox?open=social.comments:'.$comment->id))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('thread', null));
    $this->actingAsOwner($plain)->post($this->tenantUrl($plain, "/communication/inbox/social.comments/{$comment->id}/reply"), ['text' => 'Hi'])->assertNotFound();

    Http::assertNothingSent();
});
