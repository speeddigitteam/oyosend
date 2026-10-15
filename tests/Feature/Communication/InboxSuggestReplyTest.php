<?php

declare(strict_types=1);

use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\NullTextAssistant;
use App\Core\Ai\Text\TextKind;
use App\Core\Ai\Text\TextState;
use App\Modules\Communication\Models\Message as OutboxMessage;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeTextAssistant;
use Tests\Support\InteractsWithTextAssistant;
use Tests\Support\InteractsWithWhatsapp;

/*
| "Suggest a reply" in the unified inbox (ADR 0030, note of 2026-10-14): an AI draft for the composer through the core
| TextAssistant contract. The same gates as a reply; it sends, saves and marks nothing.
*/

uses(InteractsWithWhatsapp::class, InteractsWithTextAssistant::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('inbox-suggest');
    $this->waConnectCloud($this->tenant, wamid: 'wamid.OUT-SUGGEST');
    $this->contact = $this->waContact($this->tenant);
    $this->waInbound($this->tenant, '+8801711000001', 'Is the blue one in stock?')->assertOk();
    $this->conversation = $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->sole());
    $this->assistant = $this->fakeTextAssistant();

    $this->suggestUrl = fn (string $id, string $source = 'whatsapp_marketing.conversations'): string => $this->tenantUrl($this->tenant, "/communication/inbox/{$source}/{$id}/suggest");
    $this->openUrl = fn (string $id): string => $this->tenantUrl($this->tenant, '/communication/inbox?open=whatsapp_marketing.conversations:'.$id);
    $this->member = fn (array $permissions, array $scopes = []): array => $this->waMember($this->tenant, ['communication.inbox.view', ...$permissions], $scopes);
    $this->agent = fn (): array => ($this->member)(['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply']);
    $this->written = fn (): array => $this->inTenant($this->tenant, fn (): array => [
        Message::query()->where('direction', Direction::Outbound->value)->count(),
        OutboxMessage::query()->count(),
        Conversation::query()->sole()->unread_count,
    ]);
});

it('offers the button only to a member who can answer now, in a workspace with the AI app', function () {
    [$agent] = ($this->agent)();
    [$viewer] = ($this->member)(['whatsapp_marketing.conversations.view']);

    // Not entitled to the AI app yet.
    $this->actingAsMember($this->tenant, $agent)->get(($this->openUrl)($this->conversation->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canReply', true)->where('thread.aiDrafting', false));

    $this->entitle($this->tenant, 'ai');

    $this->actingAsMember($this->tenant, $agent)->get(($this->openUrl)($this->conversation->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canReply', true)->where('thread.aiDrafting', true));
    $this->actingAsMember($this->tenant, $viewer)->get(($this->openUrl)($this->conversation->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.replyAllowed', false)->where('thread.aiDrafting', false));

    // The window closed: no reply can be written, so no suggestion is offered either.
    $this->travel(25)->hours();
    $this->actingAsMember($this->tenant, $agent)->get(($this->openUrl)($this->conversation->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canReply', false)->where('thread.aiDrafting', false));
});

it('does not offer the button when the AI app is not installed, whatever the entitlement', function () {
    $this->entitle($this->tenant, 'ai');
    $this->app->instance(TextAssistant::class, new NullTextAssistant);

    $this->actingAsOwner($this->tenant)->get(($this->openUrl)($this->conversation->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canReply', true)->where('thread.aiDrafting', false));

    // Asked anyway, it says so honestly.
    $this->actingAsOwner($this->tenant)->postJson(($this->suggestUrl)($this->conversation->id))
        ->assertOk()->assertJsonPath('state', 'not_configured')->assertJsonPath('text', '');
});

it('answers with a suggestion for the newest customer message and sends, saves and marks nothing', function () {
    [$agent, $membership] = ($this->agent)();
    $this->inTenant($this->tenant, function (): void {
        foreach ([[Direction::Inbound, SenderType::Customer, 'Hello', 10], [Direction::Outbound, SenderType::Agent, 'Hi, how can we help?', 5]] as [$direction, $sender, $body, $minutes]) {
            Message::query()->forceCreate([
                'conversation_id' => $this->conversation->id, 'direction' => $direction, 'sender_type' => $sender, 'type' => MessageType::Text,
                'body' => $body, 'status' => $direction === Direction::Inbound ? MessageStatus::Received : MessageStatus::Sent, 'occurred_at' => now()->subMinutes($minutes),
            ]);
        }
    });
    $newest = $this->inTenant($this->tenant, fn (): Message => Message::query()->where('body', 'Is the blue one in stock?')->sole());
    $before = ($this->written)();
    $this->assistant->push(FakeTextAssistant::ok(TextKind::ReplySuggestion, 'Yes, the blue one is in stock.'));

    $this->actingAsMember($this->tenant, $agent)
        ->postJson(($this->suggestUrl)($this->conversation->id))
        ->assertOk()
        ->assertExactJson(['state' => 'ok', 'text' => 'Yes, the blue one is in stock.', 'message' => '', 'retryable' => false]);

    expect($this->assistant->tasks)->toHaveCount(1);
    $task = $this->assistant->tasks[0];
    expect($task->kind)->toBe(TextKind::ReplySuggestion)
        ->and($task->input)->toBe('Is the blue one in stock?')
        ->and($task->purpose)->toBe('inbox:reply:whatsapp_marketing.conversations:'.$newest->id)
        ->and($task->platform)->toBe('whatsapp')
        ->and($task->locale)->toBeNull()
        ->and($task->maxLength)->toBe(600)
        ->and($task->actorMembershipId)->toBe($membership->id)
        ->and($task->context)->toHaveCount(1)
        ->and($task->context[0]->label)->toBe('earlier_messages')
        ->and($task->context[0]->text)->toBe("Customer: Hello\nBusiness: Hi, how can we help?");

    // Nothing was sent, queued, stored or marked read.
    Http::assertNothingSent();
    expect(($this->written)())->toBe($before)->and($before[2])->toBe(1);

    // Only POST exists.
    $this->actingAsMember($this->tenant, $agent)->getJson(($this->suggestUrl)($this->conversation->id))->assertStatus(405);
});

it('shows the assistant\'s own safe message when it cannot suggest', function (TextState $state) {
    [$agent] = ($this->agent)();
    $this->assistant->push(FakeTextAssistant::refused($state, TextKind::ReplySuggestion));

    $this->actingAsMember($this->tenant, $agent)
        ->postJson(($this->suggestUrl)($this->conversation->id))
        ->assertOk()
        ->assertJsonPath('state', $state->value)
        ->assertJsonPath('text', '')
        ->assertJsonPath('message', $state->message());

    Http::assertNothingSent();
})->with([
    'not configured' => [TextState::NotConfigured],
    'disabled' => [TextState::Disabled],
    'limit reached' => [TextState::LimitReached],
    'refused' => [TextState::Refused],
    'failed' => [TextState::Failed],
]);

it('applies the reply gates: inbox permission, the source, its reply permission and the member\'s scope', function () {
    [$noInbox] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply']);
    [$noSource] = ($this->member)([]);
    [$viewer] = ($this->member)(['whatsapp_marketing.conversations.view']);
    [$ownScoped] = ($this->member)(
        ['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply'],
        ['whatsapp_marketing.conversations.view' => 'own', 'whatsapp_marketing.conversations.reply' => 'own'],
    );
    // May see every conversation but answer only their own: the conversation is shown, a suggestion is not given.
    [$replyOwn] = ($this->member)(
        ['whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply'],
        ['whatsapp_marketing.conversations.view' => 'all', 'whatsapp_marketing.conversations.reply' => 'own'],
    );
    $url = ($this->suggestUrl)($this->conversation->id);

    $this->actingAsMember($this->tenant, $noInbox)->postJson($url)->assertForbidden();
    $this->actingAsMember($this->tenant, $noSource)->postJson($url)->assertNotFound();
    $this->actingAsMember($this->tenant, $viewer)->postJson($url)->assertForbidden();
    $this->actingAsMember($this->tenant, $ownScoped)->postJson($url)->assertNotFound();
    $this->actingAsMember($this->tenant, $replyOwn)->postJson($url)->assertNotFound();
    $this->actingAsOwner($this->tenant)->postJson(($this->suggestUrl)($this->conversation->id, 'crm.leads'))->assertNotFound();
    $this->actingAsOwner($this->tenant)->postJson(($this->suggestUrl)('01JABCDEFGHJKMNPQRSTVWXYZ0'))->assertNotFound();
    $this->startNewBrowserSession()->postJson($url)->assertUnauthorized();

    expect($this->assistant->tasks)->toBe([]);
    Http::assertNothingSent();
});

it('answers 404 from another workspace', function () {
    $intruder = $this->waTenant('inbox-suggest-intruder');
    $this->entitle($intruder, 'ai');

    $this->assertNotFoundFromOtherTenant('POST', "/communication/inbox/whatsapp_marketing.conversations/{$this->conversation->id}/suggest", $this->tenant, [], $intruder);

    // Only the home workspace's control request reached the assistant.
    expect($this->assistant->tasks)->toHaveCount(1);
    Http::assertNothingSent();
});

it('refuses on the composer\'s field when no reply can be written now or there is nothing to answer', function () {
    [$agent] = ($this->agent)();

    $this->travel(25)->hours();
    $this->actingAsMember($this->tenant, $agent)->postJson(($this->suggestUrl)($this->conversation->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['text' => '24-hour window']);
    $this->travelBack();

    // Inside the window, but the customer's only message is a picture: nothing to answer in words.
    $this->inTenant($this->tenant, function (): void {
        Message::query()->where('conversation_id', $this->conversation->id)->update(['type' => MessageType::Image->value, 'body' => null]);
    });
    $this->actingAsMember($this->tenant, $agent)->postJson(($this->suggestUrl)($this->conversation->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['text' => 'There is no customer message to reply to yet.']);

    expect($this->assistant->tasks)->toBe([]);
    Http::assertNothingSent();
});

it('counts on a throttle of its own, so drafting never spends the reply or read budget', function () {
    [$agent] = ($this->agent)();
    $this->actingAsMember($this->tenant, $agent);
    $url = ($this->suggestUrl)($this->conversation->id);

    $this->postJson($url)->assertOk()->assertHeader('X-RateLimit-Limit', '20')->assertHeader('X-RateLimit-Remaining', '19');

    for ($i = 1; $i < 20; $i++) {
        $this->postJson($url)->assertOk();
    }

    $this->postJson($url)->assertTooManyRequests();

    $this->postJson($this->tenantUrl($this->tenant, "/communication/inbox/whatsapp_marketing.conversations/{$this->conversation->id}/read"))
        ->assertNoContent()
        ->assertHeader('X-RateLimit-Limit', '120');
    expect($this->assistant->tasks)->toHaveCount(20);
});
