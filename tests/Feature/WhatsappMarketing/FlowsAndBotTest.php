<?php

declare(strict_types=1);

use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\FlowManager;
use App\Modules\WhatsappMarketing\Actions\SettingsManager;
use App\Modules\WhatsappMarketing\Enums\FlowTrigger;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-flows');
    $this->waConnectCloud($this->tenant);
    $this->contact = $this->waContact($this->tenant, ['first_name' => 'Rahim']);
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
    $this->flow = fn (array $input) => $this->inTenant($this->tenant, fn () => app(FlowManager::class)->save(null, [
        'name' => 'Flow '.bin2hex(random_bytes(3)),
        'trigger' => 'keyword',
        'match_type' => 'exact',
        ...$input,
    ]));
    $this->botReplies = fn (): int => $this->inTenant($this->tenant, fn () => Message::query()->where('sender_type', 'bot')->count());
    $this->say = fn (string $text) => $this->waInbound($this->tenant, '+8801711000001', $text)->assertOk();
});

it('answers a keyword with the canned reply, with allow-listed placeholders filled', function () {
    ($this->flow)(['keywords' => ['price'], 'actions' => [['type' => 'reply', 'text' => 'Hi {{contact.first_name}}, plans start at 500 BDT.']]]);

    ($this->say)('  PRICE ');

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'text' && $request['text']['body'] === 'Hi Rahim, plans start at 500 BDT.');
    expect(($this->botReplies)())->toBe(1);
});

it('matches a keyword inside a longer message only for a contains flow', function () {
    ($this->flow)(['keywords' => ['price'], 'match_type' => 'contains', 'actions' => [['type' => 'reply', 'text' => 'Plans start at 500 BDT.']]]);

    ($this->say)('What is the price of the large plan?');
    expect(($this->botReplies)())->toBe(1);

    ($this->say)('hello there');
    expect(($this->botReplies)())->toBe(1);
});

it('says nothing when no flow matches or bots are switched off', function () {
    ($this->flow)(['keywords' => ['price'], 'actions' => [['type' => 'reply', 'text' => 'x']]]);

    ($this->say)('something else');
    expect(($this->botReplies)())->toBe(0);

    $this->inTenant($this->tenant, fn () => app(SettingsManager::class)->update(['bots_enabled' => false]));
    ($this->say)('price');

    expect(($this->botReplies)())->toBe(0);
    Http::assertNothingSent();
});

it('runs a menu: the prompt, then the chosen option by number or label', function () {
    $agent = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    $this->waAgent($this->tenant, $agent[1]);
    ($this->flow)(['keywords' => ['menu'], 'actions' => [[
        'type' => 'menu',
        'prompt' => 'How can we help?',
        'options' => [
            ['label' => 'Pricing', 'actions' => [['type' => 'reply', 'text' => 'Plans start at 500 BDT.'], ['type' => 'tag', 'tag' => 'pricing']]],
            ['label' => 'Talk to an agent', 'actions' => [['type' => 'assign', 'assignee_id' => $agent[1]->id], ['type' => 'reply', 'text' => 'An agent will join you shortly.']]],
        ],
    ]]]);

    ($this->say)('menu');
    Http::assertSent(fn (Request $request): bool => ($request['text']['body'] ?? '') === "How can we help?\n1. Pricing\n2. Talk to an agent");

    ($this->say)('Talk to an agent');

    $this->inTenant($this->tenant, function () use ($agent): void {
        $conversation = Conversation::query()->firstOrFail();

        expect($conversation->owner_id)->toBe($agent[1]->id)
            ->and($conversation->bot_paused)->toBeTrue()
            ->and($conversation->bot_state)->toBeNull();
    });
    Http::assertSent(fn (Request $request): bool => ($request['text']['body'] ?? '') === 'An agent will join you shortly.');
});

it('applies a numbered menu answer and a tag', function () {
    ($this->flow)(['keywords' => ['menu'], 'actions' => [[
        'type' => 'menu',
        'prompt' => 'Pick one',
        'options' => [['label' => 'Pricing', 'actions' => [['type' => 'reply', 'text' => 'Plans start at 500 BDT.'], ['type' => 'tag', 'tag' => 'pricing']]]],
    ]]]);

    ($this->say)('menu');
    ($this->say)('1');

    $this->inTenant($this->tenant, fn () => expect(Conversation::query()->firstOrFail()->tags)->toBe(['pricing']));
    Http::assertSent(fn (Request $request): bool => ($request['text']['body'] ?? '') === 'Plans start at 500 BDT.');
});

it('greets once per conversation window with a welcome flow', function () {
    ($this->flow)(['trigger' => 'welcome', 'match_type' => null, 'actions' => [['type' => 'reply', 'text' => 'Welcome to Acme!']]]);

    ($this->say)('hi');
    ($this->say)('anyone there?');

    expect(($this->botReplies)())->toBe(1);

    $this->travel(25)->hours();
    ($this->say)('hello again');

    expect(($this->botReplies)())->toBe(2);
});

it('steps back after a handoff and for a conversation a person owns', function () {
    ($this->flow)(['keywords' => ['human'], 'actions' => [['type' => 'reply', 'text' => 'A person will answer.'], ['type' => 'handoff']]]);
    ($this->flow)(['keywords' => ['price'], 'actions' => [['type' => 'reply', 'text' => 'Plans start at 500 BDT.']]]);

    ($this->say)('human');
    expect(($this->botReplies)())->toBe(1);

    ($this->say)('price');
    expect(($this->botReplies)())->toBe(1);
});

it('does not answer in a conversation that is assigned to a person', function () {
    ($this->flow)(['keywords' => ['price'], 'actions' => [['type' => 'reply', 'text' => 'x']]]);
    ($this->say)('hello');
    $conversation = $this->inTenant($this->tenant, fn () => Conversation::query()->firstOrFail());
    $agent = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);
    $this->waAgent($this->tenant, $agent[1]);
    $this->inTenant($this->tenant, fn () => app(ConversationManager::class)->assign($conversation, $agent[1]));

    ($this->say)('price');

    expect(($this->botReplies)())->toBe(0);
});

it('answers STOP with an unsubscribe link even when no flow exists', function () {
    ($this->say)('STOP');

    $this->inTenant($this->tenant, function (): void {
        $reply = Message::query()->where('sender_type', 'bot')->firstOrFail();

        expect($reply->body)->toContain('/communication/unsubscribe/');
    });
});

it('answers STOP honestly for a number that is not a contact', function () {
    $this->waInbound($this->tenant, '+8801999000077', 'stop')->assertOk();

    // No contact means no way to send through the platform: the request waits in the inbox for a person.
    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->where('phone', '+8801999000077')->firstOrFail();

        expect($conversation->contact_id)->toBeNull()
            ->and($conversation->messages()->where('sender_type', 'bot')->count())->toBe(0);
    });
});

it('stops answering after a handful of bot messages in ten minutes', function () {
    ($this->flow)(['keywords' => ['ping'], 'actions' => [['type' => 'reply', 'text' => 'pong']]]);

    foreach (range(1, 8) as $n) {
        ($this->say)('ping');
    }

    expect(($this->botReplies)())->toBe(5);
});

it('validates flows: a closed vocabulary, one menu and it last, no unknown placeholders', function () {
    $post = fn (array $payload) => $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, '/flows'), ['name' => 'F '.bin2hex(random_bytes(3)), 'trigger' => 'keyword', 'keywords' => ['a'], ...$payload]);

    $post(['actions' => [['type' => 'run_code', 'code' => 'system("ls")']]])->assertSessionHasErrors();
    $post(['actions' => [['type' => 'reply', 'text' => 'Hi {{user.password}}']]])->assertSessionHasErrors();
    $post(['actions' => [
        ['type' => 'menu', 'prompt' => 'A', 'options' => [['label' => 'x', 'actions' => [['type' => 'menu', 'prompt' => 'B', 'options' => []]]]]],
    ]])->assertSessionHasErrors();
    $post(['actions' => [
        ['type' => 'menu', 'prompt' => 'A', 'options' => [['label' => 'x', 'actions' => [['type' => 'handoff']]]]],
        ['type' => 'reply', 'text' => 'after'],
    ]])->assertSessionHasErrors('actions');
    $post(['actions' => []])->assertSessionHasErrors();
    $post(['actions' => [['type' => 'reply', 'text' => 'ok']], 'keywords' => []])->assertSessionHasErrors('keywords');

    $this->inTenant($this->tenant, fn () => expect(Flow::query()->count())->toBe(0));
});

it('stores only the vocabulary it knows and treats an expression-looking keyword as plain text', function () {
    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, '/flows'), [
            'name' => 'Careful',
            'trigger' => 'keyword',
            'keywords' => ['${7*7} && drop table'],
            'actions' => [['type' => 'reply', 'text' => 'ok', 'url' => 'https://evil.test', 'script' => 'alert(1)']],
        ])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        $flow = Flow::query()->where('name', 'Careful')->firstOrFail();

        expect($flow->trigger)->toBe(FlowTrigger::Keyword)
            ->and($flow->keywords)->toBe(['${7*7} && drop table'])
            ->and($flow->actions)->toEqual([['type' => 'reply', 'text' => 'ok']]);
    });
});

it('lets only holders of the flows permission change flows', function () {
    [$viewer] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view']);

    $this->actingAsMember($this->tenant, $viewer)
        ->post($this->waUrl($this->tenant, '/flows'), ['name' => 'x', 'trigger' => 'welcome', 'actions' => [['type' => 'handoff']]])
        ->assertForbidden();
});
