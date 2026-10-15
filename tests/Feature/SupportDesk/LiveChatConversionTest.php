<?php

declare(strict_types=1);

use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\ContactMerger;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Support\Actions\LiveChatConversion;
use App\Modules\Support\Actions\LiveChatManager;
use App\Modules\Support\Enums\ChatStatus;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Events\TicketCustomerReplied;
use App\Modules\Support\Events\TicketReplied;
use App\Modules\Support\Inbox\LiveChatInboxSource;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSupport;

/*
| Website chat conversions (ADR 0032, amendment of 2026-10-14): a member saves the visitor as a shared contact, or turns
| the chat into a ticket. Both are explicit actions, narrowed like the chat itself, audited with ids only.
*/

uses(InteractsWithSupport::class);

beforeEach(function () {
    $this->tenant = $this->supportTenant('sup-conv');
    $this->chat = fn (?string $name = 'Rahim Uddin', ?string $email = 'rahim@example.com', string $text = 'My order #42 has not arrived', ?Tenant $tenant = null): ChatConversation => $this->inTenant(
        $tenant ?? $this->tenant,
        fn (): ChatConversation => app(LiveChatManager::class)->start($text, $name, $email, 'https://www.example.com/orders')['conversation'],
    );
    $this->fresh = fn (ChatConversation $chat): ChatConversation => $this->inTenant($this->tenant, fn (): ChatConversation => ChatConversation::query()->findOrFail($chat->id));
    $this->page = fn (string $path = ''): string => $this->supportUrl($this->tenant, '/live-chat'.$path);
    $this->owner = fn () => $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
});

// ---- save as contact -------------------------------------------------------------------------------------------------

it('saves the visitor as a new shared contact owned by the member, links it and audits ids only', function () {
    $chat = ($this->chat)();

    $this->actingAsOwner($this->tenant)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.contact', null)
            ->where('thread.canSaveContact', true));

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/contact"))->assertRedirect();

    $linked = ($this->fresh)($chat);
    $contact = $this->inTenant($this->tenant, fn (): Contact => Contact::query()->findOrFail($linked->contact_id));
    expect($contact->first_name)->toBe('Rahim')
        ->and($contact->last_name)->toBe('Uddin')
        ->and($contact->email)->toBe('rahim@example.com')
        ->and($contact->owner_id)->toBe(($this->owner)()->id);

    $audit = $this->auditTrail($this->tenant, 'support.live_chat.contact_linked');
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->properties)->toMatchArray(['conversation_id' => $chat->id, 'contact_id' => $contact->id, 'contact_created' => true]);
    $json = json_encode($audit->first()->properties, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('rahim@example.com')->not->toContain('Rahim');

    // The page shows the linked contact; the unified inbox now carries its id.
    $this->actingAsOwner($this->tenant)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.contact.name', 'Rahim Uddin')
            ->where('thread.contact.search', 'rahim@example.com')
            ->where('thread.canSaveContact', false));

    $this->inTenant($this->tenant, function () use ($chat, $contact): void {
        $owner = $this->ownerOf($this->tenant);
        $source = app(LiveChatInboxSource::class);
        $items = $source->items(new InboxQuery($owner, ($this->owner)()->id, InboxState::cases()));

        expect($items[0]->contactId)->toBe($contact->id)
            ->and($source->thread($chat->id, $owner)?->contactId)->toBe($contact->id);
    });
});

it('links the existing contact with the same email address instead of creating a duplicate', function () {
    $existing = $this->inTenant($this->tenant, fn (): Contact => app(ContactDirectory::class)->createContact(['first_name' => 'Rahim', 'email' => 'Rahim@Example.com']));
    $chat = ($this->chat)('Someone Else', 'rahim@example.com');

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/contact"))->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->fresh)($chat)->contact_id)->toBe($existing->id)
        ->and($this->inTenant($this->tenant, fn () => Contact::query()->count()))->toBe(1)
        ->and($this->auditTrail($this->tenant, 'support.live_chat.contact_linked')->first()->properties['contact_created'])->toBeFalse();
});

it('keeps a chat that is already linked as it is', function () {
    $chat = ($this->chat)();

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/contact"))->assertRedirect();
    $first = ($this->fresh)($chat)->contact_id;
    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/contact"))->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->fresh)($chat)->contact_id)->toBe($first)
        ->and($this->inTenant($this->tenant, fn () => Contact::query()->count()))->toBe(1)
        ->and($this->auditTrail($this->tenant, 'support.live_chat.contact_linked'))->toHaveCount(1);
});

it('saves a visitor with only a name or only an email address, and refuses one with neither', function () {
    $named = ($this->chat)('Karim', null);
    $mailed = ($this->chat)(null, 'nadia.k@example.com');
    $anonymous = ($this->chat)(null, null);

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$named->id}/contact"))->assertSessionHasNoErrors();
    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$mailed->id}/contact"))->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function () use ($named, $mailed): void {
        $byName = Contact::query()->findOrFail(ChatConversation::query()->findOrFail($named->id)->contact_id);
        $byEmail = Contact::query()->findOrFail(ChatConversation::query()->findOrFail($mailed->id)->contact_id);

        expect([$byName->first_name, $byName->last_name, $byName->email])->toBe(['Karim', null, null])
            ->and([$byEmail->first_name, $byEmail->email])->toBe(['nadia.k', 'nadia.k@example.com']);
    });

    $this->actingAsOwner($this->tenant)->get(($this->page)('?open='.$anonymous->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canSaveContact', false));
    $this->actingAsOwner($this->tenant)->from(($this->page)())->post(($this->page)("/{$anonymous->id}/contact"))
        ->assertSessionHasErrors('contact');
    expect(($this->fresh)($anonymous)->contact_id)->toBeNull();
});

it('needs the contacts manage permission and the chat\'s reach to save a contact', function () {
    $chat = ($this->chat)();
    $theirs = ($this->chat)('Other', 'other@example.com');
    [, $colleague] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply'], ['support.tickets.view' => 'all', 'support.tickets.reply' => 'all']);
    $this->inTenant($this->tenant, fn () => app(LiveChatManager::class)->reply($theirs, 'Mine now', $colleague));

    [$viewer] = $this->supportMember($this->tenant, ['support.tickets.view'], ['support.tickets.view' => 'all']);
    $this->actingAsMember($this->tenant, $viewer)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canSaveContact', false));
    $this->actingAsMember($this->tenant, $viewer)->post(($this->page)("/{$chat->id}/contact"))->assertForbidden();

    // Own scope, not an agent: a colleague's chat is not disclosed.
    [$own] = $this->supportMember($this->tenant, ['support.tickets.view', 'core.contacts.manage', 'core.contacts.view'], ['support.tickets.view' => 'own', 'core.contacts.manage' => 'all', 'core.contacts.view' => 'all']);
    $this->actingAsMember($this->tenant, $own)->post(($this->page)("/{$theirs->id}/contact"))->assertNotFound();

    [$manager] = $this->supportMember($this->tenant, ['support.tickets.view', 'core.contacts.manage'], ['support.tickets.view' => 'all', 'core.contacts.manage' => 'own']);
    $this->actingAsMember($this->tenant, $manager)->post(($this->page)("/{$chat->id}/contact"))->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->fresh)($chat)->contact_id)->not->toBeNull()
        ->and(($this->fresh)($theirs)->contact_id)->toBeNull();

    // A member who cannot see that contact is told a contact is linked, without its name.
    [$blind] = $this->supportMember($this->tenant, ['support.tickets.view', 'core.contacts.view'], ['support.tickets.view' => 'all', 'core.contacts.view' => 'own']);
    $this->actingAsMember($this->tenant, $blind)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.contact.name', null)->where('thread.contact.search', null));
});

it('does not save another workspace\'s visitor', function () {
    $chat = ($this->chat)();

    $intruder = $this->supportTenant('sup-conv-intruder');

    // The control request (the record's own owner) really links it; the intruder created nothing in its workspace.
    $this->assertNotFoundFromOtherTenant('POST', "/support/live-chat/{$chat->id}/contact", $this->tenant, intruder: $intruder);
    expect($this->inTenant($intruder, fn () => Contact::query()->count()))->toBe(0);
});

it('re-points linked chats when their contact is merged into another', function () {
    $chat = ($this->chat)();
    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/contact"))->assertRedirect();
    $duplicate = ($this->fresh)($chat)->contact_id;

    $this->inTenant($this->tenant, function () use ($duplicate): void {
        $canonical = app(ContactDirectory::class)->createContact(['first_name' => 'Rahim', 'last_name' => 'U.', 'email' => 'r.uddin@example.com']);
        app(ContactMerger::class)->merge($canonical, Contact::query()->findOrFail($duplicate), []);
    });

    $merged = ($this->fresh)($chat);
    expect($merged->contact_id)->not->toBe($duplicate)
        ->and($this->inTenant($this->tenant, fn () => Contact::query()->findOrFail($merged->contact_id)->email))->toBe('r.uddin@example.com');
});

// ---- create a ticket --------------------------------------------------------------------------------------------------

it('turns a chat into a ticket with the transcript as an internal note, emailing nobody', function () {
    Mail::fake();
    Event::fake([TicketReplied::class, TicketCustomerReplied::class]);
    $chat = ($this->chat)();
    $this->inTenant($this->tenant, fn () => app(LiveChatManager::class)->reply($chat, 'Sorry to hear that, let me check.', ($this->owner)()));
    $this->inTenant($this->tenant, fn () => app(LiveChatManager::class)->receive(($this->fresh)($chat), 'Thanks!'));

    $this->actingAsOwner($this->tenant)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.canCreateTicket', true)
            ->where('thread.ticket', null)
            ->where('thread.ticketForm.subject', 'My order #42 has not arrived')
            ->has('thread.ticketForm.priorities', 4));

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/ticket"), ['subject' => 'Order #42 missing', 'priority' => 'high'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $linked = ($this->fresh)($chat);
    expect($linked->ticket_id)->not->toBeNull()
        ->and($linked->status)->toBe(ChatStatus::Open);

    $this->inTenant($this->tenant, function () use ($linked): void {
        $ticket = Ticket::query()->findOrFail($linked->ticket_id);
        expect($ticket->subject)->toBe('Order #42 missing')
            ->and($ticket->channel)->toBe(TicketChannel::Chat)
            ->and($ticket->priority->value)->toBe('high')
            ->and($ticket->requester_name)->toBe('Rahim Uddin')
            ->and($ticket->requester_email)->toBe('rahim@example.com')
            ->and($ticket->contact_id)->toBeNull()
            ->and($ticket->source_key)->toBe(LiveChatConversion::SOURCE_PREFIX.$linked->id);

        // No customer-visible message: the whole chat is one internal note.
        $messages = Message::query()->where('ticket_id', $ticket->id)->get();
        expect($messages)->toHaveCount(1)
            ->and($messages[0]->kind)->toBe(MessageKind::InternalNote)
            ->and($messages[0]->body)->toContain('Rahim Uddin: My order #42 has not arrived')
            ->and($messages[0]->body)->toContain($this->ownerOf($this->tenant)->name.': Sorry to hear that, let me check.')
            ->and($messages[0]->body)->toContain('Rahim Uddin: Thanks!')
            ->and($messages[0]->body)->toContain('https://www.example.com/orders');
    });

    Event::assertNotDispatched(TicketReplied::class);
    Event::assertNotDispatched(TicketCustomerReplied::class);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();

    $audit = $this->auditTrail($this->tenant, 'support.live_chat.converted_to_ticket');
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->properties)->toMatchArray(['conversation_id' => $chat->id, 'ticket_id' => $linked->ticket_id, 'messages' => 3]);
    $json = json_encode($audit->first()->properties, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('rahim')->not->toContain('order');

    // The chat shows its ticket; the ticket links back to the chat.
    $number = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($linked->ticket_id)->number);
    $this->actingAsOwner($this->tenant)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('thread.ticket.number', $number)
            ->where('thread.ticket.canOpen', true)
            ->where('thread.canCreateTicket', false)
            ->where('thread.ticketForm', null));
    $this->actingAsOwner($this->tenant)->get($this->supportUrl($this->tenant, '/tickets/'.$linked->ticket_id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('ticket.fromChat', $chat->id));
});

it('creates one ticket per chat however often it is asked', function () {
    $chat = ($this->chat)();

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/ticket"), ['subject' => 'First'])->assertSessionHasNoErrors();
    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/ticket"), ['subject' => 'Second'])->assertSessionHasNoErrors();

    // A second call on a stale copy of the conversation (as a concurrent request would hold) changes nothing either.
    $again = $this->inTenant($this->tenant, fn () => app(LiveChatConversion::class)->createTicket($chat, ['subject' => 'Third'], ($this->owner)()));
    expect($again['created'])->toBeFalse();

    $this->inTenant($this->tenant, function () use ($chat, $again): void {
        expect(Ticket::query()->count())->toBe(1)
            ->and(Ticket::query()->value('subject'))->toBe('First')
            ->and($again['ticket']->id)->toBe(ChatConversation::query()->findOrFail($chat->id)->ticket_id)
            ->and(Message::query()->count())->toBe(1);
    });
    expect($this->auditTrail($this->tenant, 'support.live_chat.converted_to_ticket'))->toHaveCount(1)
        ->and($this->auditTrail($this->tenant, 'support.ticket.created'))->toHaveCount(1);
});

it('closes the chat in the same step when asked and allowed', function () {
    $chat = ($this->chat)();

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/ticket"), ['close' => true])->assertSessionHasNoErrors();

    $closed = ($this->fresh)($chat);
    expect($closed->status)->toBe(ChatStatus::Closed)
        ->and($closed->ticket_id)->not->toBeNull()
        ->and($this->auditTrail($this->tenant, 'support.live_chat.closed'))->toHaveCount(1)
        // Without a subject the visitor's first message is used.
        ->and($this->inTenant($this->tenant, fn () => Ticket::query()->value('subject')))->toBe('My order #42 has not arrived');

    // Closing needs the resolve permission: nothing is created without it.
    $other = ($this->chat)('Asha', 'asha@example.com');
    [$creator] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.create'], ['support.tickets.view' => 'all']);
    $this->actingAsMember($this->tenant, $creator)->get(($this->page)('?open='.$other->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canCreateTicket', true)->where('thread.canClose', false));
    $this->actingAsMember($this->tenant, $creator)->post(($this->page)("/{$other->id}/ticket"), ['close' => true])->assertNotFound();
    expect(($this->fresh)($other)->ticket_id)->toBeNull();

    $this->actingAsMember($this->tenant, $creator)->post(($this->page)("/{$other->id}/ticket"), ['close' => false])->assertSessionHasNoErrors();
    expect(($this->fresh)($other)->status)->toBe(ChatStatus::Open)
        ->and(($this->fresh)($other)->ticket_id)->not->toBeNull();
});

it('needs an email address or a contact for a ticket, and uses the linked contact', function () {
    $chat = ($this->chat)('Karim', null);

    $this->actingAsOwner($this->tenant)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canCreateTicket', false)->where('thread.ticketNeedsContact', true));
    $this->actingAsOwner($this->tenant)->from(($this->page)())->post(($this->page)("/{$chat->id}/ticket"))->assertSessionHasErrors('ticket');
    expect($this->inTenant($this->tenant, fn () => Ticket::query()->count()))->toBe(0);

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/contact"))->assertSessionHasNoErrors();
    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/ticket"))->assertSessionHasNoErrors();

    $linked = ($this->fresh)($chat);
    expect($this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($linked->ticket_id)->contact_id))->toBe($linked->contact_id);
});

it('splits a long transcript into several internal notes', function () {
    $chat = ($this->chat)();
    $this->inTenant($this->tenant, function () use ($chat): void {
        foreach (range(1, 12) as $i) {
            app(LiveChatManager::class)->receive(ChatConversation::query()->findOrFail($chat->id), $i.' '.Str::repeat('x', 1990));
        }
    });

    $this->actingAsOwner($this->tenant)->post(($this->page)("/{$chat->id}/ticket"))->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function () use ($chat): void {
        $notes = Message::query()->where('ticket_id', ChatConversation::query()->findOrFail($chat->id)->ticket_id)->orderBy('created_at')->orderBy('id')->get();

        expect($notes->count())->toBe(2)
            ->and($notes->every(fn (Message $note): bool => $note->kind === MessageKind::InternalNote && mb_strlen($note->body) <= LiveChatConversion::TRANSCRIPT_CHUNK))->toBeTrue()
            ->and(substr_count($notes->implode('body', "\n"), Str::repeat('x', 1990)))->toBe(12);
    });
});

it('needs the ticket create permission and the chat\'s reach to create a ticket', function () {
    $chat = ($this->chat)();
    $theirs = ($this->chat)('Other', 'other@example.com');
    [, $colleague] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply'], ['support.tickets.view' => 'all', 'support.tickets.reply' => 'all']);
    $this->inTenant($this->tenant, fn () => app(LiveChatManager::class)->reply($theirs, 'Mine now', $colleague));

    [$viewer] = $this->supportMember($this->tenant, ['support.tickets.view'], ['support.tickets.view' => 'all']);
    $this->actingAsMember($this->tenant, $viewer)->get(($this->page)('?open='.$chat->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('thread.canCreateTicket', false)->where('thread.ticketForm', null));
    $this->actingAsMember($this->tenant, $viewer)->post(($this->page)("/{$chat->id}/ticket"))->assertForbidden();

    [$own] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.create'], ['support.tickets.view' => 'own']);
    $this->actingAsMember($this->tenant, $own)->post(($this->page)("/{$theirs->id}/ticket"))->assertNotFound();

    expect($this->inTenant($this->tenant, fn () => Ticket::query()->count()))->toBe(0);
});

it('does not turn another workspace\'s chat into a ticket', function () {
    $chat = ($this->chat)();

    $intruder = $this->supportTenant('sup-conv-intruder');

    $this->assertNotFoundFromOtherTenant('POST', "/support/live-chat/{$chat->id}/ticket", $this->tenant, ['subject' => 'x'], $intruder);
    expect($this->inTenant($intruder, fn () => Ticket::query()->count()))->toBe(0);
});

it('keeps conversations with their contact and ticket links isolated per tenant', function () {
    $this->assertTenantIsolated(ChatConversation::class, function (): ChatConversation {
        $contact = app(ContactDirectory::class)->createContact(['first_name' => 'Iso', 'email' => 'iso@example.com']);

        return ChatConversation::query()->create([
            'visitor_token_hash' => hash('sha256', Str::random(43)),
            'status' => ChatStatus::Open,
            'last_message_at' => now(),
            'contact_id' => $contact->id,
        ]);
    });
});
