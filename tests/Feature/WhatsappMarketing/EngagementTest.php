<?php

declare(strict_types=1);

use App\Modules\WhatsappMarketing\Actions\Analytics;
use App\Modules\WhatsappMarketing\Actions\FeedbackCollector;
use App\Modules\WhatsappMarketing\Actions\FlowManager;
use App\Modules\WhatsappMarketing\Actions\SettingsManager;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\FeedbackStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Events\FeedbackReceived;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\ConversationNote;
use App\Modules\WhatsappMarketing\Models\Feedback;
use App\Modules\WhatsappMarketing\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-engage');
    $this->waConnectCloud($this->tenant);
    $this->contact = $this->waContact($this->tenant, ['first_name' => 'Rahim']);
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
    $this->waAgent($this->tenant, $this->membership);
    $this->say = fn (string $text) => $this->waInbound($this->tenant, '+8801711000001', $text)->assertOk();
    ($this->say)('Hello');
    $this->conversation = $this->inTenant($this->tenant, fn () => Conversation::query()->firstOrFail());
    $this->post = fn (string $path, array $data = []) => $this->actingAsMember($this->tenant, $this->user)
        ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}".$path), $data);
    // A customer's tap on a reply button or a list row, as Meta delivers it.
    $this->tap = fn (string $kind, string $title) => $this->waWebhook($this->tenant, $this->waMessagePayload('109876543210', [[
        'from' => '8801711000001',
        'id' => 'wamid.IN'.Str::upper(Str::random(12)),
        'timestamp' => (string) now()->timestamp,
        'type' => 'interactive',
        'interactive' => ['type' => $kind, $kind => ['id' => 'opt_1', 'title' => $title]],
    ]], '+8801711000001'))->assertOk();
});

describe('interactive messages', function () {
    it('sends an agent reply with reply buttons inside the window', function () {
        ($this->post)('/messages', ['body' => 'Which plan do you want?', 'interactive' => ['kind' => 'buttons', 'options' => ['Basic', 'Pro']]])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'button'
            && $request['interactive']['body']['text'] === 'Which plan do you want?'
            && array_column(array_column($request['interactive']['action']['buttons'], 'reply'), 'title') === ['Basic', 'Pro']);

        $this->inTenant($this->tenant, function (): void {
            $message = Message::query()->where('direction', Direction::Outbound->value)->firstOrFail();

            expect($message->type)->toBe(MessageType::Interactive)
                ->and($message->interactive)->toBe(['kind' => 'buttons', 'button' => null, 'options' => ['Basic', 'Pro']]);
        });
    });

    it('sends a list behind a button label', function () {
        ($this->post)('/messages', ['body' => 'Pick a branch', 'interactive' => ['kind' => 'list', 'button' => 'Branches', 'options' => ['Dhaka', 'Chattogram', 'Sylhet', 'Khulna']]])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => ($request['interactive']['type'] ?? null) === 'list'
            && $request['interactive']['action']['button'] === 'Branches'
            && count($request['interactive']['action']['sections'][0]['rows']) === 4);
    });

    it('refuses choices outside the limits and sends nothing', function () {
        ($this->post)('/messages', ['body' => 'x', 'interactive' => ['kind' => 'buttons', 'options' => ['A', 'B', 'C', 'D']]])->assertSessionHasErrors('interactive.options');
        ($this->post)('/messages', ['body' => 'x', 'interactive' => ['kind' => 'buttons', 'options' => ['A button title that is too long']]])->assertSessionHasErrors();
        ($this->post)('/messages', ['body' => 'x', 'interactive' => ['kind' => 'list', 'options' => ['A']]])->assertSessionHasErrors('interactive.button');
        ($this->post)('/messages', ['body' => 'x', 'interactive' => ['kind' => 'buttons', 'options' => ['Same', 'same']]])->assertSessionHasErrors();
        ($this->post)('/messages', ['body' => str_repeat('a', 1100), 'interactive' => ['kind' => 'buttons', 'options' => ['A']]])->assertSessionHasErrors('body');

        Http::assertNothingSent();
    });

    it('sends a chatbot menu as buttons and takes the tapped button as the answer', function () {
        $this->inTenant($this->tenant, fn () => app(FlowManager::class)->save(null, [
            'name' => 'Menu', 'trigger' => 'keyword', 'match_type' => 'exact', 'keywords' => ['menu'],
            'actions' => [[
                'type' => 'menu',
                'prompt' => 'How can we help?',
                'options' => [
                    ['label' => 'Pricing', 'actions' => [['type' => 'reply', 'text' => 'Plans start at 500 BDT.']]],
                    ['label' => 'Support', 'actions' => [['type' => 'tag', 'tag' => 'support']]],
                ],
            ]],
        ]));

        ($this->say)('menu');
        Http::assertSent(fn (Request $request): bool => ($request['interactive']['type'] ?? null) === 'button'
            && $request['interactive']['body']['text'] === 'How can we help?');

        ($this->tap)('button_reply', 'Pricing');
        Http::assertSent(fn (Request $request): bool => ($request['text']['body'] ?? null) === 'Plans start at 500 BDT.');
    });

    it('uses a list for a longer menu and numbered text when a label does not fit a row', function () {
        $options = fn (array $labels) => array_map(fn (string $label) => ['label' => $label, 'actions' => [['type' => 'handoff']]], $labels);
        $this->inTenant($this->tenant, function () use ($options): void {
            app(FlowManager::class)->save(null, ['name' => 'Long', 'trigger' => 'keyword', 'match_type' => 'exact', 'keywords' => ['branches'],
                'actions' => [['type' => 'menu', 'prompt' => 'Which branch?', 'options' => $options(['Dhaka', 'Chattogram', 'Sylhet', 'Khulna'])]]]);
            app(FlowManager::class)->save(null, ['name' => 'Wordy', 'trigger' => 'keyword', 'match_type' => 'exact', 'keywords' => ['topics'],
                'actions' => [['type' => 'menu', 'prompt' => 'Which topic?', 'options' => $options(['A rather long topic label here', 'Short'])]]]);
        });

        ($this->say)('branches');
        Http::assertSent(fn (Request $request): bool => ($request['interactive']['type'] ?? null) === 'list');

        ($this->say)('topics');
        Http::assertSent(fn (Request $request): bool => ($request['text']['body'] ?? null) === "Which topic?\n1. A rather long topic label here\n2. Short");
    });
});

describe('internal notes', function () {
    it('adds a note the team sees and the customer never receives', function () {
        ($this->post)('/notes', ['body' => 'Customer prefers a call after 5 pm.'])->assertSessionHasNoErrors();

        Http::assertNothingSent();

        $this->actingAsMember($this->tenant, $this->user)
            ->get($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}"))
            ->assertInertia(fn ($page) => $page
                ->component('Modules/WhatsappMarketing/Pages/Inbox/Show')
                ->where('notes.0.body', 'Customer prefers a call after 5 pm.')
                ->where('notes.0.removable', true));
    });

    it('lets the author or a manager remove a note, and nobody else', function () {
        ($this->post)('/notes', ['body' => 'Mine'])->assertSessionHasNoErrors();
        $note = $this->inTenant($this->tenant, fn () => ConversationNote::query()->firstOrFail());
        [$viewer] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view'], ['whatsapp_marketing.conversations.view' => 'all']);
        $path = $this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/notes/{$note->id}");

        $this->actingAsMember($this->tenant, $viewer)->delete($path)->assertForbidden();
        $this->actingAsMember($this->tenant, $this->user)->delete($path)->assertRedirect();

        $this->inTenant($this->tenant, fn () => expect(ConversationNote::query()->count())->toBe(0));
    });

    it('needs the reply permission to write a note', function () {
        [$viewer] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view'], ['whatsapp_marketing.conversations.view' => 'all']);

        $this->actingAsMember($this->tenant, $viewer)
            ->post($this->waUrl($this->tenant, "/inbox/{$this->conversation->id}/notes"), ['body' => 'x'])
            ->assertForbidden();
    });

    it('isolates notes and feedback between tenants', function () {
        $conversation = fn () => Conversation::query()->create(['phone' => '+88018'.random_int(10000000, 99999999), 'status' => ConversationStatus::Open, 'unread_count' => 0]);

        $this->assertTenantIsolated(ConversationNote::class, fn () => ConversationNote::query()->create(['conversation_id' => $conversation()->id, 'body' => 'Note']));
        $this->assertTenantIsolated(Feedback::class, fn () => Feedback::query()->create([
            'conversation_id' => $conversation()->id, 'status' => FeedbackStatus::Pending, 'requested_at' => now(), 'expires_at' => now()->addDay(),
        ]));
    });

    it('answers 404 for a note from another workspace', function () {
        ($this->post)('/notes', ['body' => 'Private'])->assertSessionHasNoErrors();
        $note = $this->inTenant($this->tenant, fn () => ConversationNote::query()->firstOrFail());

        $this->assertNotFoundFromOtherTenant('DELETE', "/whatsapp/inbox/{$this->conversation->id}/notes/{$note->id}", $this->tenant, intruder: $this->waTenant('wa-engage-other'));
    });
});

describe('customer feedback', function () {
    beforeEach(function () {
        $this->inTenant($this->tenant, fn () => app(SettingsManager::class)->update(['feedback_enabled' => true]));
        ($this->post)('/messages', ['body' => 'Your order ships today.'])->assertSessionHasNoErrors();
        $this->close = fn () => ($this->post)('/status', ['status' => 'closed'])->assertSessionHasNoErrors();
    });

    it('asks for a rating as a list when a person closes the conversation, and records the answer', function () {
        Event::fake([FeedbackReceived::class]);
        ($this->close)();

        Http::assertSent(fn (Request $request): bool => ($request['interactive']['type'] ?? null) === 'list'
            && count($request['interactive']['action']['sections'][0]['rows']) === 5);

        ($this->tap)('list_reply', '4 - Good');

        $this->inTenant($this->tenant, function (): void {
            $feedback = Feedback::query()->firstOrFail();
            $conversation = Conversation::query()->firstOrFail();

            expect($feedback->status)->toBe(FeedbackStatus::Answered)
                ->and($feedback->rating)->toBe(4)
                ->and($feedback->owner_id)->toBe($this->membership->id)
                ->and($conversation->status)->toBe(ConversationStatus::Closed)
                ->and($conversation->unread_count)->toBe(0);
        });
        Http::assertSent(fn (Request $request): bool => ($request['text']['body'] ?? null) === 'Thank you for your feedback.');
        Event::assertDispatched(FeedbackReceived::class, fn (FeedbackReceived $event): bool => $event->payload()['rating'] === 4);
    });

    it('takes a typed number, including a Bengali digit', function () {
        expect(FeedbackCollector::rating('5'))->toBe(5)
            ->and(FeedbackCollector::rating('৩'))->toBe(3)
            ->and(FeedbackCollector::rating('2 stars'))->toBe(2)
            ->and(FeedbackCollector::rating('10'))->toBeNull()
            ->and(FeedbackCollector::rating('thanks'))->toBeNull()
            ->and(FeedbackCollector::rating('6'))->toBeNull();
    });

    it('ends the question when the customer writes something else, and reopens the conversation as usual', function () {
        ($this->close)();
        ($this->say)('I have another question');
        ($this->say)('3');

        $this->inTenant($this->tenant, function (): void {
            expect(Feedback::query()->firstOrFail()->status)->toBe(FeedbackStatus::Expired)
                ->and(Conversation::query()->firstOrFail()->status)->toBe(ConversationStatus::Open);
        });
    });

    it('does not ask when the workspace has it off, outside the window, or twice in a day', function () {
        $this->inTenant($this->tenant, fn () => app(SettingsManager::class)->update(['feedback_enabled' => false]));
        ($this->close)();
        $this->inTenant($this->tenant, fn () => expect(Feedback::query()->count())->toBe(0));

        $this->inTenant($this->tenant, fn () => app(SettingsManager::class)->update(['feedback_enabled' => true]));
        ($this->post)('/status', ['status' => 'open']);
        ($this->close)();
        ($this->post)('/status', ['status' => 'open']);
        ($this->close)();
        $this->inTenant($this->tenant, fn () => expect(Feedback::query()->count())->toBe(1));

        $this->travel(25)->hours();
        ($this->post)('/status', ['status' => 'open']);
        ($this->close)();
        $this->inTenant($this->tenant, fn () => expect(Feedback::query()->count())->toBe(1));
    });

    it('reports requests, answers and the average rating', function () {
        ($this->close)();
        ($this->say)('5');

        $overview = $this->inTenant($this->tenant, fn () => app(Analytics::class)->overview(now()->subDay(), now()->addMinute()));

        expect($overview['feedback'])->toBe(['requested' => 1, 'answered' => 1, 'averageX100' => 500, 'byRating' => [5 => 1, 4 => 0, 3 => 0, 2 => 0, 1 => 0]]);
    });
});

describe('lists filled from rules', function () {
    it('adds the visible contacts with a phone that match every rule', function () {
        $dhaka = $this->waContact($this->tenant, ['first_name' => 'Dhaka', 'phone' => '+8801711000002', 'city' => 'Dhaka']);
        $this->waContact($this->tenant, ['first_name' => 'Sylhet', 'phone' => '+8801711000003', 'city' => 'Sylhet']);
        $this->waContact($this->tenant, ['first_name' => 'No phone', 'phone' => null, 'city' => 'Dhaka']);
        $list = $this->waList($this->tenant, []);

        $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, "/lists/{$list->id}/members/rules"), ['match' => 'all', 'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Dhaka']]])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->tenant, fn () => expect($list->members()->pluck('contact_id')->all())->toBe([$dhaka->id]));
    });

    it('matches on what the customer did in WhatsApp', function () {
        $this->inTenant($this->tenant, fn () => $this->conversation->forceFill(['tags' => ['vip']])->save());
        $quiet = $this->waContact($this->tenant, ['first_name' => 'Quiet', 'phone' => '+8801711000009']);
        $list = $this->waList($this->tenant, []);
        $rules = fn (array $rules, string $match = 'all') => $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, "/lists/{$list->id}/members/rules"), ['match' => $match, 'rules' => $rules])
            ->assertSessionHasNoErrors();

        $rules([['field' => 'conversation_tag', 'operator' => 'equals', 'value' => 'vip'], ['field' => 'wrote_within_days', 'operator' => 'within', 'value' => '7']]);
        $this->inTenant($this->tenant, fn () => expect($list->members()->pluck('contact_id')->all())->toBe([$this->contact->id]));

        $rules([['field' => 'has_written', 'operator' => 'is_false']]);
        $this->inTenant($this->tenant, fn () => expect($list->members()->pluck('contact_id')->sort()->values()->all())->toBe(collect([$this->contact->id, $quiet->id])->sort()->values()->all()));
    });

    it('refuses an unknown or malformed rule', function () {
        $list = $this->waList($this->tenant, []);
        $post = fn (array $rules) => $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, "/lists/{$list->id}/members/rules"), ['match' => 'all', 'rules' => $rules]);

        $post([['field' => 'email', 'operator' => 'equals', 'value' => 'x']])->assertSessionHasErrors('rules.0');
        $post([['field' => 'wrote_within_days', 'operator' => 'within', 'value' => '999']])->assertSessionHasErrors('rules.0');
        $post([['field' => 'created_after', 'operator' => 'on_or_after', 'value' => 'yesterday']])->assertSessionHasErrors('rules.0');
        $post([])->assertSessionHasErrors('rules');
    });

    it('only adds contacts the member may see', function () {
        $this->waContact($this->tenant, ['first_name' => 'Hidden', 'phone' => '+8801711000005', 'city' => 'Dhaka']);
        [$scoped, $membership] = $this->waMember($this->tenant, ['whatsapp_marketing.lists.view', 'whatsapp_marketing.lists.manage', 'core.contacts.view'], ['core.contacts.view' => 'own']);
        $mine = $this->waContact($this->tenant, ['first_name' => 'Mine', 'phone' => '+8801711000006', 'city' => 'Dhaka'], $membership);
        $list = $this->waList($this->tenant, []);

        $this->actingAsMember($this->tenant, $scoped)
            ->post($this->waUrl($this->tenant, "/lists/{$list->id}/members/rules"), ['match' => 'all', 'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Dhaka']]])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->tenant, fn () => expect($list->members()->pluck('contact_id')->all())->toBe([$mine->id]));
    });
});
