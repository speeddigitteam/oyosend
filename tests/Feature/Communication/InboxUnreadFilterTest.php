<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Modules\Communication\Actions\UnifiedInbox;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Support\Enums\ChatStatus;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\Ticket;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;
use Tests\Support\InteractsWithSupport;
use Tests\Support\InteractsWithWhatsapp;

/*
| The inbox's "Unread" filter is applied by the server (ADR 0030, note of 2026-10-14): every source narrows by its own
| unread notion, the one that makes its items' `unread` greater than zero, in items() and counts() alike, and paging,
| the board and the list refresh follow it.
*/

uses(InteractsWithWhatsapp::class, InteractsWithSupport::class, InteractsWithSocial::class);

beforeEach(function () {
    config(['social_comments.enabled' => true]);
    $this->tenant = $this->waTenant('inbox-unread');
    $this->entitle($this->tenant, 'support', 'social');
    $this->owner = $this->ownerOf($this->tenant);
    $this->membership = $this->membershipOf($this->tenant, $this->owner);

    $this->wa = fn (string $phone, int $minutesAgo, int $unread, ConversationStatus $status = ConversationStatus::Open): Conversation => $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->forceCreate([
        'phone' => $phone, 'status' => $status, 'unread_count' => $unread,
        'last_message_at' => now()->subMinutes($minutesAgo)->startOfSecond(), 'last_message_preview' => "Message from {$phone}",
    ]));
    $this->query = fn (bool $unread, array $states = [], int $limit = 25): InboxQuery => new InboxQuery($this->owner, $this->membership->id, $states === [] ? InboxState::cases() : $states, limit: $limit, unread: $unread);
    $this->source = fn (string $key): InboxSource => app(UnifiedInbox::class)->availableFor($this->owner)[$key];
    $this->ids = fn (array $items): array => array_map(fn (InboxItem $item): string => $item->id, $items);
    // The filter and the item agree: exactly the items with an unread count are returned.
    $this->agrees = function (InboxSource $source): void {
        $all = $source->items(($this->query)(false));
        $expected = array_values(array_map(fn (InboxItem $item): string => $item->id, array_filter($all, fn (InboxItem $item): bool => $item->unread > 0)));

        expect(($this->ids)($source->items(($this->query)(true))))->toBe($expected);
    };
    $this->inbox = fn (string $query = ''): string => $this->tenantUrl($this->tenant, '/communication/inbox'.$query);
    $this->poll = fn (string $query = ''): string => $this->tenantUrl($this->tenant, '/communication/inbox/poll'.$query);
});

it('narrows WhatsApp conversations to unread ones in items, counts and every page', function () {
    $a = ($this->wa)('+8801711000001', 5, 2);
    ($this->wa)('+8801711000002', 7, 0);
    $b = ($this->wa)('+8801711000003', 10, 1);
    $c = ($this->wa)('+8801711000004', 15, 4);
    ($this->wa)('+8801711000005', 17, 0, ConversationStatus::Closed);
    $closed = ($this->wa)('+8801711000006', 20, 3, ConversationStatus::Closed);

    $this->inTenant($this->tenant, function () use ($a, $b, $c, $closed): void {
        $source = ($this->source)('whatsapp_marketing.conversations');

        expect(($this->ids)($source->items(($this->query)(true))))->toBe([$a->id, $b->id, $c->id, $closed->id])
            ->and($source->items(($this->query)(false)))->toHaveCount(6)
            ->and($source->counts(($this->query)(true)))->toBe(['unassigned' => 3, 'active' => 0, 'closed' => 1])
            ->and($source->counts(($this->query)(false)))->toBe(['unassigned' => 4, 'active' => 0, 'closed' => 2])
            ->and(($this->ids)($source->items(($this->query)(true, [InboxState::Closed]))))->toBe([$closed->id]);
        ($this->agrees)($source);

        // Paging continues inside the filter, without gaps.
        $inbox = app(UnifiedInbox::class);
        $pages = [];
        $after = null;

        do {
            $page = $inbox->page(['whatsapp_marketing.conversations' => $source], ($this->query)(true, limit: 2), $after);
            $pages[] = array_map(fn (array $entry): string => $entry['item']->id, $page['items']);
            $after = $page['next'];
        } while ($after !== null);

        expect($pages)->toBe([[$a->id, $b->id], [$c->id, $closed->id]]);
    });
});

it('takes unread=1 on the page, the board and the refresh, and keeps it in the filters', function () {
    ($this->wa)('+8801711000011', 5, 2);
    ($this->wa)('+8801711000012', 7, 0);
    ($this->wa)('+8801711000013', 10, 1);
    ($this->wa)('+8801711000014', 20, 3, ConversationStatus::Closed);
    $this->actingAsOwner($this->tenant);

    $this->get(($this->inbox)('?unread=1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.unread', true)
            ->has('list.items', 2)
            ->where('list.items.0.unread', 2)
            ->where('list.items.1.unread', 1)
            ->where('counts', ['unassigned' => 2, 'active' => 0, 'closed' => 1]));
    $this->get(($this->inbox)())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.unread', false)
            ->has('list.items', 3)
            ->where('counts', ['unassigned' => 3, 'active' => 0, 'closed' => 1]));
    $this->get(($this->inbox)('?view=board&unread=1'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.unread', true)
            ->where('columns.0.total', 2)
            ->has('columns.0.items', 2)
            ->where('columns.2.total', 1));

    $this->getJson(($this->poll)('?unread=1'))->assertOk()->assertJsonCount(2, 'items');
    $this->getJson(($this->poll)('?unread=1&state=all'))->assertOk()->assertJsonCount(3, 'items');
    $this->getJson(($this->poll)('?unread=0'))->assertOk()->assertJsonCount(3, 'items');
    $this->getJson(($this->poll)('?unread=maybe'))->assertUnprocessable()->assertJsonValidationErrors('unread');
});

it('counts a ticket as unread while the customer waits for a reply', function () {
    $waiting = $this->supportTicket($this->tenant, ['subject' => 'Waiting']);
    $answered = $this->supportTicket($this->tenant, ['subject' => 'Answered']);
    $again = $this->supportTicket($this->tenant, ['subject' => 'Wrote again']);
    $quiet = $this->supportTicket($this->tenant, ['subject' => 'No customer message']);
    $resolved = $this->supportTicket($this->tenant, ['subject' => 'Resolved but waiting']);
    $this->inTenant($this->tenant, function () use ($waiting, $answered, $again, $quiet, $resolved): void {
        $at = fn (int $minutes) => now()->subMinutes($minutes)->startOfSecond();
        $waiting->forceFill(['last_customer_message_at' => $at(30), 'last_agent_message_at' => null, 'last_activity_at' => $at(5)])->save();
        $answered->forceFill(['last_customer_message_at' => $at(30), 'last_agent_message_at' => $at(20), 'last_activity_at' => $at(6)])->save();
        $again->forceFill(['last_customer_message_at' => $at(10), 'last_agent_message_at' => $at(20), 'last_activity_at' => $at(7)])->save();
        $quiet->forceFill(['last_customer_message_at' => null, 'last_agent_message_at' => $at(20), 'last_activity_at' => $at(8)])->save();
        $resolved->forceFill(['status' => TicketStatus::Resolved, 'last_customer_message_at' => $at(10), 'last_agent_message_at' => null, 'last_activity_at' => $at(9)])->save();
    });

    $this->inTenant($this->tenant, function () use ($waiting, $again, $resolved): void {
        $source = ($this->source)('support.tickets');

        expect(($this->ids)($source->items(($this->query)(true))))->toBe([$waiting->id, $again->id, $resolved->id])
            ->and($source->counts(($this->query)(true)))->toBe(['unassigned' => 2, 'active' => 0, 'closed' => 1])
            ->and($source->counts(($this->query)(false)))->toBe(['unassigned' => 4, 'active' => 0, 'closed' => 1]);
        ($this->agrees)($source);
        expect(Ticket::query()->count())->toBe(5);
    });
});

it('counts a Facebook thread as unread while one of its comments awaits an answer', function () {
    $page = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => '1000', 'name' => 'Brand Page', 'token_expires_at' => null]);
    $comment = fn (array $attributes = []): AudienceComment => $this->inTenant($this->tenant, fn (): AudienceComment => AudienceComment::query()->forceCreate([
        'account_id' => $page->id, 'platform' => 'facebook', 'external_id' => '1000_'.Str::lower(Str::random(8)), 'external_post_id' => '1000_post1',
        'author_external_id' => '777', 'author_name' => 'Nadia Karim', 'message' => 'What is the price?', 'remote_state' => RemoteState::Visible,
        'source' => 'webhook', 'commented_at' => now()->subMinutes(30)->startOfSecond(), 'received_at' => now()->subMinutes(30)->startOfSecond(),
        ...$attributes,
    ]));
    $waiting = $comment(['commented_at' => now()->subMinutes(10)->startOfSecond()]);
    $handled = $comment(['handled_at' => now()->subMinutes(5)]);
    // An answered thread whose customer wrote again is waiting once more.
    $followUp = $comment(['handled_at' => now()->subMinutes(50), 'commented_at' => now()->subMinutes(60)->startOfSecond()]);
    $comment(['parent_id' => $followUp->id, 'message' => 'Any news?', 'commented_at' => now()->subMinutes(20)->startOfSecond()]);

    $this->inTenant($this->tenant, function () use ($waiting, $handled, $followUp): void {
        $source = ($this->source)('social.comments');

        expect(($this->ids)($source->items(($this->query)(true))))->toBe([$waiting->id, $followUp->id])
            ->and($source->counts(($this->query)(true)))->toBe(['unassigned' => 2, 'active' => 0, 'closed' => 0])
            ->and($source->counts(($this->query)(false)))->toBe(['unassigned' => 2, 'active' => 0, 'closed' => 1])
            ->and($source->items(($this->query)(true, [InboxState::Closed])))->toBe([])
            ->and(($this->ids)($source->items(($this->query)(false, [InboxState::Closed]))))->toBe([$handled->id]);
        ($this->agrees)($source);
    });
});

it('counts a website chat as unread while it has visitor messages nobody read', function () {
    [$unread, $read, $closed] = $this->inTenant($this->tenant, function (): array {
        $chat = fn (int $unread, int $minutes, ChatStatus $status = ChatStatus::Open): ChatConversation => ChatConversation::query()->forceCreate([
            'visitor_token_hash' => hash('sha256', Str::random(43)), 'status' => $status, 'message_count' => 1, 'unread_count' => $unread,
            'last_message_at' => now()->subMinutes($minutes)->startOfSecond(),
        ]);

        return [$chat(2, 5), $chat(0, 6), $chat(1, 7, ChatStatus::Closed)];
    });

    $this->inTenant($this->tenant, function () use ($unread, $closed): void {
        $source = ($this->source)('support.live_chat');

        expect(($this->ids)($source->items(($this->query)(true))))->toBe([$unread->id, $closed->id])
            ->and($source->counts(($this->query)(true)))->toBe(['unassigned' => 1, 'active' => 0, 'closed' => 1])
            ->and($source->counts(($this->query)(false)))->toBe(['unassigned' => 2, 'active' => 0, 'closed' => 1]);
        ($this->agrees)($source);
    });
    expect($read->unread_count)->toBe(0);
});

it('never shows another workspace\'s unread conversations', function () {
    $mine = ($this->wa)('+8801711000031', 5, 1);
    $intruder = $this->waTenant('inbox-unread-intruder');
    $theirs = $this->inTenant($intruder, fn (): Conversation => Conversation::query()->forceCreate([
        'phone' => '+8801711000032', 'status' => ConversationStatus::Open, 'unread_count' => 5, 'last_message_at' => now()->subMinute(),
    ]));

    $this->startNewBrowserSession()->actingAsOwner($intruder)
        ->getJson($this->tenantUrl($intruder, '/communication/inbox/poll?unread=1&state=all'))
        ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.key', 'whatsapp_marketing.conversations:'.$theirs->id);
    $this->startNewBrowserSession()->actingAsOwner($this->tenant)
        ->getJson(($this->poll)('?unread=1&state=all'))
        ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.key', 'whatsapp_marketing.conversations:'.$mine->id);
});
