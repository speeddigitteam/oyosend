<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Contacts\Models\Contact;
use App\Modules\Support\Actions\SupportContactMergeReference;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Automation\Actions\AddInternalNoteAction;
use App\Modules\Support\Automation\Actions\AssignTicketAction;
use App\Modules\Support\Automation\Actions\CreateTicketAction;
use App\Modules\Support\Contracts\InboundTicketMessage;
use App\Modules\Support\Contracts\InboundTicketReceiver;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\AutomationActionReceipt;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    $this->tenant = $this->supportTenant('sup-auto');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->supportAt('2026-03-09 10:00');
    $this->receive = fn (array $overrides = []) => $this->inTenant($this->tenant, fn () => app(InboundTicketReceiver::class)->receive(new InboundTicketMessage(
        ...[
            'channel' => 'email',
            'externalId' => '<msg-1@mail.example>',
            'fromEmail' => 'Nadia@Example.test',
            'fromName' => 'Nadia Karim',
            'subject' => 'Cannot log in',
            'body' => 'Please help.',
            'inReplyToTicketNumber' => null,
            'receivedAt' => CarbonImmutable::now(),
            ...$overrides,
        ],
    )));
});

it('opens a ticket from an inbound message, linking an existing contact by email', function () {
    $contact = $this->inTenant($this->tenant, fn () => Contact::factory()->create(['first_name' => 'Nadia', 'email' => 'nadia@example.test']));

    $result = ($this->receive)();

    expect($result->created)->toBeTrue()->and($result->duplicate)->toBeFalse()->and($result->number)->toBe('TKT-00001');

    $ticket = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($result->ticketId));
    expect($ticket->contact_id)->toBe($contact->id)
        ->and($ticket->requester_email)->toBe('nadia@example.test')
        ->and($ticket->channel)->toBe(TicketChannel::Email);
});

it('is idempotent on the external message id', function () {
    $first = ($this->receive)();
    $again = ($this->receive)();

    expect($again->duplicate)->toBeTrue()
        ->and($again->ticketId)->toBe($first->ticketId)
        ->and($this->inTenant($this->tenant, fn () => Ticket::query()->count()))->toBe(1)
        ->and($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(1);

    // The next real ticket still gets the next number: no gap, no skipped number.
    expect($this->supportTicket($this->tenant)->number)->toBe('TKT-00002');
});

it('threads a reply by ticket number only for the requester, and follows merges', function () {
    $first = ($this->receive)();
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->changeStatus(Ticket::query()->findOrFail($first->ticketId), TicketStatus::Resolved, $this->owner));

    $reply = ($this->receive)(['externalId' => '<msg-2@mail.example>', 'subject' => 'Re: [TKT-00001]', 'body' => 'Still failing.', 'inReplyToTicketNumber' => 'TKT-00001']);

    expect($reply->created)->toBeFalse()->and($reply->ticketId)->toBe($first->ticketId);
    $ticket = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($first->ticketId));
    expect($ticket->status)->toBe(TicketStatus::Open)->and($ticket->reopened_count)->toBe(1);

    // A stranger quoting the (guessable) number does not get into the thread.
    $stranger = ($this->receive)(['externalId' => '<msg-3@mail.example>', 'fromEmail' => 'mallory@example.test', 'inReplyToTicketNumber' => 'TKT-00001']);

    expect($stranger->created)->toBeTrue()->and($stranger->ticketId)->not->toBe($first->ticketId)
        ->and($this->inTenant($this->tenant, fn () => Message::query()->where('ticket_id', $first->ticketId)->where('kind', MessageKind::CustomerReply)->count()))->toBe(2);
});

it('rejects invalid inbound messages', function () {
    expect(fn () => ($this->receive)(['fromEmail' => 'not-an-email']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->receive)(['channel' => 'carrier-pigeon']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->receive)(['channel' => 'internal']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->receive)(['externalId' => '']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->receive)(['body' => '   ']))->toThrow(ValidationException::class);
});

it('registers the Support triggers and actions with the automation registry', function () {
    $registry = app(AutomationRegistry::class);

    foreach (['support.ticket.created', 'support.ticket.assigned', 'support.ticket.customer_replied', 'support.ticket.resolved', 'support.ticket.sla_breached', 'support.csat.submitted'] as $key) {
        expect($registry->trigger($key))->not->toBeNull();
    }

    foreach (['support.ticket.create', 'support.ticket.assign', 'support.ticket.add_note'] as $key) {
        expect($registry->action($key))->not->toBeNull();
    }
});

it('creates a ticket once per idempotency key', function () {
    $action = fn () => $this->inTenant($this->tenant, fn () => app(CreateTicketAction::class)->execute([
        'subject' => 'From a workflow', 'body' => 'Created by automation', 'requester_email' => 'flow@example.test', 'priority' => 'high',
    ], 'run-1:step-3'));

    $first = $action();
    $second = $action();

    expect($second)->toBe($first)
        ->and($this->inTenant($this->tenant, fn () => Ticket::query()->count()))->toBe(1)
        ->and($this->inTenant($this->tenant, fn () => AutomationActionReceipt::query()->count()))->toBe(1)
        ->and($this->supportTicket($this->tenant)->number)->toBe('TKT-00002');

    $other = $this->inTenant($this->tenant, fn () => app(CreateTicketAction::class)->execute([
        'subject' => 'Another', 'body' => 'x', 'requester_email' => 'flow@example.test',
    ], 'run-1:step-4'));

    expect($other['ticket_id'])->not->toBe($first['ticket_id']);
});

it('assigns and notes through actions without repeating on a retry', function () {
    $agentMembership = $this->supportOwnerAgent($this->tenant);
    $ticket = $this->supportTicket($this->tenant);

    $assign = fn () => $this->inTenant($this->tenant, fn () => app(AssignTicketAction::class)->execute(['ticket_id' => $ticket->id, 'assignee_id' => $agentMembership->id], 'k-assign'));
    $note = fn () => $this->inTenant($this->tenant, fn () => app(AddInternalNoteAction::class)->execute(['ticket_id' => $ticket->id, 'body' => 'Auto note'], 'k-note'));

    expect($assign())->toBe($assign())->and($note())->toBe($note());

    $this->inTenant($this->tenant, function () use ($ticket, $agentMembership): void {
        expect(Ticket::query()->findOrFail($ticket->id)->owner_id)->toBe($agentMembership->id)
            ->and(Message::query()->where('ticket_id', $ticket->id)->where('kind', MessageKind::InternalNote)->count())->toBe(1);
    });
});

it('has no action that speaks to a customer', function () {
    $keys = array_map(
        static fn (string $class): string => $class::key(),
        [CreateTicketAction::class, AssignTicketAction::class, AddInternalNoteAction::class],
    );

    expect($keys)->not->toContain('support.ticket.reply')->and($keys)->not->toContain('support.ticket.send_reply');
});

it('repoints tickets when a duplicate contact is merged', function () {
    $keep = $this->inTenant($this->tenant, fn () => Contact::factory()->create());
    $duplicate = $this->inTenant($this->tenant, fn () => Contact::factory()->create());
    $ticket = $this->supportTicket($this->tenant, ['contact_id' => $duplicate->id]);

    $counts = app(SupportContactMergeReference::class)->merge($this->tenant->id, $keep->id, $duplicate->id);

    expect($counts)->toBe(['support_tickets' => 1])
        ->and($this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id))->contact_id)->toBe($keep->id);

    // Another workspace's tickets are never touched.
    $other = $this->supportTenant('sup-auto-other');
    $foreign = $this->supportTicket($other, ['requester_email' => 'x@example.test']);
    expect(app(SupportContactMergeReference::class)->merge($other->id, $keep->id, $duplicate->id))->toBe([])
        ->and($this->inTenant($other, fn () => Ticket::query()->findOrFail($foreign->id))->contact_id)->toBeNull();
});
