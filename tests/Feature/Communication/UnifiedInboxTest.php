<?php

declare(strict_types=1);

use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Modules\Communication\Actions\UnifiedInbox;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Tests\Support\InteractsWithSupport;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class, InteractsWithSupport::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('unified-inbox');
    $this->entitle($this->tenant, 'support');
    $this->owner = $this->ownerOf($this->tenant);
    $this->ownerMembership = $this->membershipOf($this->tenant, $this->owner);
    $this->url = $this->tenantUrl($this->tenant, '/communication/inbox');

    $this->conversation = fn (string $phone, int $hoursAgo, ConversationStatus $status = ConversationStatus::Open, ?string $ownerId = null): Conversation => $this->inTenant($this->tenant, fn (): Conversation => Conversation::query()->forceCreate([
        'phone' => $phone, 'status' => $status, 'owner_id' => $ownerId, 'unread_count' => 1,
        'last_message_at' => now()->subHours($hoursAgo)->startOfSecond(), 'last_message_preview' => "Message from {$phone}",
    ]));

    $this->ticket = function (string $subject, int $hoursAgo, TicketStatus $status = TicketStatus::Open, ?string $ownerId = null): Ticket {
        $ticket = $this->supportTicket($this->tenant, ['subject' => $subject]);

        return $this->inTenant($this->tenant, function () use ($ticket, $hoursAgo, $status, $ownerId): Ticket {
            $ticket->forceFill(['status' => $status, 'owner_id' => $ownerId, 'last_activity_at' => now()->subHours($hoursAgo)->startOfSecond()])->save();

            return $ticket;
        });
    };
});

it('merges WhatsApp conversations and support tickets newest first and pages through them without gaps', function () {
    ($this->conversation)('+8801711000001', 1);
    ($this->ticket)('Second', 2);
    ($this->conversation)('+8801711000003', 3);
    ($this->ticket)('Fourth', 4);
    ($this->conversation)('+8801711000005', 5);

    $titles = $this->inTenant($this->tenant, function (): array {
        $inbox = app(UnifiedInbox::class);
        $sources = $inbox->availableFor($this->owner);
        $query = new InboxQuery($this->owner, $this->ownerMembership->id, InboxState::cases(), limit: 2);
        $seen = [];
        $after = null;

        do {
            $page = $inbox->page($sources, $query, $after);
            $seen[] = array_map(fn (array $entry): string => $entry['item']->preview, $page['items']);
            $after = $page['next'];
        } while ($after !== null);

        return $seen;
    });

    expect($titles)->toBe([
        ['Message from +8801711000001', $titles[0][1]],
        ['Message from +8801711000003', $titles[1][1]],
        ['Message from +8801711000005'],
    ])->and($titles[0][1])->toContain('Second')->and($titles[1][1])->toContain('Fourth');
});

it('shows a board with one column per state and the total of each', function () {
    $owner = $this->ownerMembership->id;
    ($this->conversation)('+8801711000011', 1);
    ($this->conversation)('+8801711000012', 2, ownerId: $owner);
    ($this->conversation)('+8801711000013', 3, ConversationStatus::Closed);
    ($this->ticket)('Unassigned ticket', 4, TicketStatus::New);
    ($this->ticket)('Resolved ticket', 5, TicketStatus::Resolved, $owner);

    $this->actingAsOwner($this->tenant)->get($this->url.'?view=board')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Communication/Pages/Inbox/Index')
            ->where('counts', ['unassigned' => 2, 'active' => 1, 'closed' => 2])
            ->where('columns.0.state', 'unassigned')
            ->has('columns.0.items', 2)
            ->where('columns.0.items.1.sourceLabel', 'Support tickets')
            ->where('columns.1.items.0.owner', $this->owner->name)
            ->where('columns.2.total', 2)
            ->where('list', null));
});

it('shows a member only the sources they may use, and the page only to inbox users', function () {
    ($this->conversation)('+8801711000021', 1);
    ($this->ticket)('Hidden ticket', 2);
    [$agent] = $this->waMember($this->tenant, ['communication.inbox.view', 'whatsapp_marketing.conversations.view'], ['whatsapp_marketing.conversations.view' => 'all']);
    [$outsider] = $this->waMember($this->tenant, ['whatsapp_marketing.conversations.view'], ['whatsapp_marketing.conversations.view' => 'all']);

    $this->actingAsMember($this->tenant, $agent)->get($this->url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sources', [['key' => 'whatsapp_marketing.conversations', 'label' => 'WhatsApp', 'channel' => 'whatsapp']])
            ->has('list.items', 1)
            ->where('list.items.0.source', 'whatsapp_marketing.conversations'));

    $this->actingAsMember($this->tenant, $outsider)->get($this->url)->assertForbidden();
});

it('filters by owner, state and search in every source', function () {
    $owner = $this->ownerMembership->id;
    ($this->conversation)('+8801711000031', 1, ownerId: $owner);
    ($this->conversation)('+8801711000032', 2);
    ($this->ticket)('Refund please', 3, TicketStatus::Open, $owner);
    ($this->ticket)('Closed one', 4, TicketStatus::Closed, $owner);

    $this->actingAsOwner($this->tenant)->get($this->url.'?owner=me')
        ->assertInertia(fn ($page) => $page->has('list.items', 2)->where('counts', ['unassigned' => 0, 'active' => 2, 'closed' => 1]));

    $this->actingAsOwner($this->tenant)->get($this->url.'?state=all&search=refund')
        ->assertInertia(fn ($page) => $page->has('list.items', 1)->where('list.items.0.preview', fn (string $preview) => str_contains($preview, 'Refund please')));

    $this->actingAsOwner($this->tenant)->get($this->url.'?source=support.tickets&state=closed')
        ->assertInertia(fn ($page) => $page->has('list.items', 1)->where('list.items.0.state', 'closed'));
});

it('rejects a page position that it did not issue', function () {
    $this->actingAsOwner($this->tenant)->from($this->url)->get($this->url.'?after=not-a-position')->assertSessionHasErrors('after');

    $forged = rtrim(strtr(base64_encode((string) json_encode(['crm.leads' => ['2026-10-13 10:00:00', '01JZZZZZZZZZZZZZZZZZZZZZZZ']])), '+/', '-_'), '=');
    $this->actingAsOwner($this->tenant)->from($this->url)->get($this->url.'?after='.$forged)->assertSessionHasErrors('after');
});

it('leaves out the tickets of a workspace that is not entitled to support', function () {
    $other = $this->waTenant('unified-inbox-wa-only');

    $this->actingAsOwner($other)->get($this->tenantUrl($other, '/communication/inbox'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sources.0.key', 'whatsapp_marketing.conversations')->has('sources', 1));
});
