<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Core\Contacts\Models\Contact;
use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketCreated;
use App\Modules\Support\Events\TicketReopened;
use App\Modules\Support\Events\TicketResolved;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    $this->tenant = $this->supportTenant('sup-life');
    $this->supportAt('2026-03-09 10:00');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
});

it('numbers tickets per workspace without gaps', function () {
    $other = $this->supportTenant('sup-life-other');

    $numbers = collect(range(1, 3))->map(fn () => $this->supportTicket($this->tenant)->number)->all();
    $otherNumber = $this->supportTicket($other)->number;

    expect($numbers)->toBe(['TKT-00001', 'TKT-00002', 'TKT-00003'])
        ->and($otherNumber)->toBe('TKT-00001');
});

it('hands a number back when the creating transaction fails', function () {
    $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        try {
            DB::transaction(function (): void {
                app(TicketManager::class)->create(['subject' => 'Doomed', 'body' => 'x', 'requester_email' => 'a@example.test'], null);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }
    });

    expect($this->supportTicket($this->tenant)->number)->toBe('TKT-00002')
        ->and($this->inTenant($this->tenant, fn () => Ticket::query()->count()))->toBe(2);
});

it('never allocates a second number for a repeated source key', function () {
    $first = $this->inTenant($this->tenant, fn () => app(TicketManager::class)->create(
        ['subject' => 'Hello', 'body' => 'Body', 'requester_email' => 'a@example.test'],
        null,
        TicketChannel::Email,
        'inbound:email:msg-1',
    ));
    $again = $this->inTenant($this->tenant, fn () => app(TicketManager::class)->create(
        ['subject' => 'Hello', 'body' => 'Body', 'requester_email' => 'a@example.test'],
        null,
        TicketChannel::Email,
        'inbound:email:msg-1',
    ));

    expect($again->id)->toBe($first->id)
        ->and($this->supportTicket($this->tenant)->number)->toBe('TKT-00002');
});

it('honours the workspace prefix and padding', function () {
    $this->inTenant($this->tenant, fn () => app(SupportSettings::class)->update(['ticket_prefix' => 'HELP-', 'number_padding' => 3]));

    expect($this->supportTicket($this->tenant)->number)->toBe('HELP-001');
});

it('needs a requester and a body', function () {
    $this->inTenant($this->tenant, function (): void {
        $manager = app(TicketManager::class);

        expect(fn () => $manager->create(['subject' => 'No requester', 'body' => 'x'], null))->toThrow(ValidationException::class)
            ->and(fn () => $manager->create(['subject' => 'No body', 'body' => '  ', 'requester_email' => 'a@example.test'], null))->toThrow(ValidationException::class);

        expect(Ticket::query()->count())->toBe(0);
    });
});

it('records the first message, history and announces the ticket', function () {
    Event::fake([TicketCreated::class]);

    $ticket = $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function () use ($ticket): void {
        $messages = Message::query()->where('ticket_id', $ticket->id)->get();

        expect($messages)->toHaveCount(1)
            ->and($messages->first()->kind)->toBe(MessageKind::CustomerReply)
            ->and($ticket->status)->toBe(TicketStatus::New)
            ->and(TicketEvent::query()->where('ticket_id', $ticket->id)->pluck('kind')->all())->toBe([TicketEventKind::Created]);
    });

    Event::assertDispatched(TicketCreated::class, fn (TicketCreated $event) => $event->payload()['number'] === 'TKT-00001');
});

it('treats a public reply as the first response and an internal note as not', function () {
    $ticket = $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function () use ($ticket): void {
        $manager = app(TicketManager::class);
        $manager->addMessage($ticket, MessageKind::InternalNote, 'Checking logs', $this->owner);

        $afterNote = Ticket::query()->findOrFail($ticket->id);
        expect($afterNote->first_responded_at)->toBeNull()->and($afterNote->status)->toBe(TicketStatus::New);

        $manager->addMessage($ticket, MessageKind::PublicReply, 'Please reset your password.', $this->owner);

        $afterReply = Ticket::query()->findOrFail($ticket->id);
        expect($afterReply->first_responded_at)->not->toBeNull()
            ->and($afterReply->status)->toBe(TicketStatus::Open)
            ->and($afterReply->last_agent_message_at)->not->toBeNull();
    });
});

it('moves through resolved to reopened when the customer writes back', function () {
    Event::fake([TicketResolved::class, TicketReopened::class]);
    $ticket = $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function () use ($ticket): void {
        $manager = app(TicketManager::class);
        $manager->addMessage($ticket, MessageKind::PublicReply, 'Done?', $this->owner, thenStatus: TicketStatus::Resolved);

        $resolved = Ticket::query()->findOrFail($ticket->id);
        expect($resolved->status)->toBe(TicketStatus::Resolved)->and($resolved->resolved_at)->not->toBeNull();

        $manager->addMessage($ticket, MessageKind::CustomerReply, 'Still broken.', null);

        $reopened = Ticket::query()->findOrFail($ticket->id);
        expect($reopened->status)->toBe(TicketStatus::Open)
            ->and($reopened->resolved_at)->toBeNull()
            ->and($reopened->reopened_count)->toBe(1);
    });

    Event::assertDispatched(TicketResolved::class);
    Event::assertDispatched(TicketReopened::class);
});

it('refuses impossible status changes and public replies on a closed ticket', function () {
    $ticket = $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function () use ($ticket): void {
        $manager = app(TicketManager::class);

        // Merged is only ever reached through a merge; New is only ever a starting point.
        expect(fn () => $manager->changeStatus($ticket, TicketStatus::Merged, $this->owner))->toThrow(ValidationException::class);

        $manager->changeStatus($ticket, TicketStatus::Open, $this->owner);

        expect(fn () => $manager->changeStatus($ticket, TicketStatus::New, $this->owner))->toThrow(ValidationException::class);

        $manager->changeStatus($ticket, TicketStatus::Closed, $this->owner);

        expect(fn () => $manager->addMessage($ticket, MessageKind::PublicReply, 'Late reply', $this->owner))->toThrow(ValidationException::class);
        // An internal note is still allowed on a closed ticket.
        $manager->addMessage($ticket, MessageKind::InternalNote, 'Post-mortem note', $this->owner);
    });
});

it('keeps the messages immutable and the history append-only', function () {
    $ticket = $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function () use ($ticket): void {
        $event = TicketEvent::query()->where('ticket_id', $ticket->id)->firstOrFail();

        expect(fn () => $event->update(['kind' => TicketEventKind::Updated]))->toThrow(LogicException::class);
    });
});

it('enforces the monthly ticket limit of the plan, once per new ticket', function () {
    $plan = $this->createFreePlan(['key' => 'two-tickets', 'modules' => ['support'], 'limits' => ['support.tickets_per_month' => 2]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $this->supportTicket($this->tenant);
    $this->supportTicket($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        expect(app(UsageLimits::class)->used('support.tickets_per_month'))->toBe(2)
            ->and(fn () => app(TicketManager::class)->create(['subject' => 'Third', 'body' => 'x', 'requester_email' => 'a@example.test'], null))
            ->toThrow(ValidationException::class);

        // Replies and status changes are not tickets.
        $ticket = Ticket::query()->firstOrFail();
        app(TicketManager::class)->addMessage($ticket, MessageKind::PublicReply, 'Hi', null);

        expect(app(UsageLimits::class)->used('support.tickets_per_month'))->toBe(2);
    });
});

it('refuses a contact from another workspace', function () {
    $other = $this->supportTenant('sup-life-foreign');
    $foreign = $this->inTenant($other, fn () => Contact::factory()->create());

    $this->inTenant($this->tenant, function () use ($foreign): void {
        expect(fn () => app(TicketManager::class)->create(['subject' => 'x', 'body' => 'y', 'contact_id' => $foreign->id], null))
            ->toThrow(ValidationException::class);
    });
});
