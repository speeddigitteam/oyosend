<?php

declare(strict_types=1);

use App\Modules\Support\Actions\SlaConfiguration;
use App\Modules\Support\Actions\SlaMonitor;
use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketSlaBreached;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    $this->tenant = $this->supportTenant('sup-sla');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->calendar = $this->supportCalendar($this->tenant);          // Mon-Fri 09:00-17:00 Dhaka
    $this->policy = $this->supportPolicy($this->tenant, calendar: $this->calendar);
    $this->queue = $this->supportQueueWithPolicy($this->tenant, $this->policy);
    $this->local = fn (?CarbonImmutable $instant): ?string => $instant?->setTimezone('Asia/Dhaka')->format('Y-m-d H:i');
    $this->open = fn (array $overrides = []): Ticket => $this->supportTicket($this->tenant, ['queue_id' => $this->queue->id, ...$overrides]);
});

it('computes due times over business hours in the workspace timezone', function () {
    $this->supportAt('2026-03-09 16:30');     // Monday

    $ticket = ($this->open)();

    // 60 minutes to first response: 30 left on Monday, 30 on Tuesday morning.
    expect(($this->local)($ticket->first_response_due_at?->toImmutable()))->toBe('2026-03-10 09:30')
        // 480 minutes to resolve: 30 on Monday + 450 on Tuesday = Tuesday 16:30.
        ->and(($this->local)($ticket->resolution_due_at?->toImmutable()))->toBe('2026-03-10 16:30')
        ->and($ticket->sla_policy_id)->toBe($this->policy->id);
});

it('uses a calendar timezone of its own when it has one', function () {
    $ny = $this->supportCalendar($this->tenant, timezone: 'America/New_York');
    $policy = $this->supportPolicy($this->tenant, calendar: $ny);
    $queue = $this->supportQueueWithPolicy($this->tenant, $policy);
    $this->supportAt('2026-03-09 20:00');     // Monday 20:00 Dhaka = 10:00 New York (EDT)

    $ticket = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);

    expect($ticket->first_response_due_at?->toImmutable()->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-03-09 11:00');
});

it('counts around the clock without a calendar', function () {
    $policy = $this->supportPolicy($this->tenant);       // no calendar
    $queue = $this->supportQueueWithPolicy($this->tenant, $policy);
    $this->supportAt('2026-03-07 23:30');                // Saturday night

    $ticket = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);

    expect(($this->local)($ticket->first_response_due_at?->toImmutable()))->toBe('2026-03-08 00:30');
});

it('falls back to the workspace default policy when the queue has none', function () {
    $this->inTenant($this->tenant, fn () => app(SupportSettings::class)->update(['default_sla_policy_id' => $this->policy->id]));
    $this->supportAt('2026-03-09 10:00');

    $ticket = $this->supportTicket($this->tenant);

    expect($ticket->sla_policy_id)->toBe($this->policy->id)
        ->and(($this->local)($ticket->first_response_due_at?->toImmutable()))->toBe('2026-03-09 11:00');
});

it('has no targets and no breaches without a policy', function () {
    $ticket = $this->supportTicket($this->tenant);

    expect($ticket->sla_policy_id)->toBeNull()->and($ticket->first_response_due_at)->toBeNull();
});

it('pauses while waiting on the customer and resumes with the remaining working minutes', function () {
    $this->supportAt('2026-03-09 09:00');
    $ticket = ($this->open)();                       // first response due 10:00 (60 min)
    $manager = fn () => app(TicketManager::class);

    $this->supportAt('2026-03-09 09:40');            // 20 minutes remain
    $this->inTenant($this->tenant, fn () => $manager()->changeStatus($ticket, TicketStatus::WaitingCustomer, $this->owner));

    $paused = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));
    expect($paused->sla_paused_at)->not->toBeNull()
        ->and($paused->first_response_remaining_minutes)->toBe(20)
        ->and($paused->resolution_remaining_minutes)->toBe(480 - 40);

    // A day later (Tuesday 14:00) the customer answers: the clock resumes with the same 20 minutes.
    $this->supportAt('2026-03-10 14:00');
    $this->inTenant($this->tenant, fn () => $manager()->addMessage($ticket, MessageKind::CustomerReply, 'Here you go', null));

    $resumed = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));
    expect($resumed->status)->toBe(TicketStatus::Open)
        ->and($resumed->sla_paused_at)->toBeNull()
        ->and(($this->local)($resumed->first_response_due_at?->toImmutable()))->toBe('2026-03-10 14:20')
        ->and($resumed->first_response_remaining_minutes)->toBeNull();

    $kinds = $this->inTenant($this->tenant, fn () => TicketEvent::query()->where('ticket_id', $ticket->id)->pluck('kind')->all());
    expect($kinds)->toContain(TicketEventKind::SlaPaused)->toContain(TicketEventKind::SlaResumed);
});

it('does not pause when the policy says not to', function () {
    $policy = $this->supportPolicy($this->tenant, calendar: $this->calendar, pause: false);
    $queue = $this->supportQueueWithPolicy($this->tenant, $policy);
    $this->supportAt('2026-03-09 09:00');
    $ticket = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);

    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->changeStatus($ticket, TicketStatus::WaitingCustomer, $this->owner));

    expect($this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id))->sla_paused_at)->toBeNull();
});

it('judges a ticket resolved while paused on the time it had left', function () {
    $this->supportAt('2026-03-09 09:00');
    $ticket = ($this->open)();
    $manager = fn () => app(TicketManager::class);

    $this->supportAt('2026-03-09 10:00');
    $this->inTenant($this->tenant, fn () => $manager()->changeStatus($ticket, TicketStatus::WaitingCustomer, $this->owner));
    $this->supportAt('2026-03-12 15:00');
    $this->inTenant($this->tenant, fn () => $manager()->changeStatus($ticket, TicketStatus::Resolved, $this->owner));

    $resolved = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));

    expect($resolved->sla_paused_at)->toBeNull()
        ->and($resolved->resolved_at?->lessThanOrEqualTo($resolved->resolution_due_at))->toBeTrue();
});

it('marks and announces each missed target exactly once', function () {
    Event::fake([TicketSlaBreached::class]);
    $this->supportAt('2026-03-09 09:00');
    $ticket = ($this->open)();                      // FR due 10:00, resolution due 17:00

    $this->supportAt('2026-03-09 09:59');
    expect($this->inTenant($this->tenant, fn () => app(SlaMonitor::class)->run()))->toBe(0);

    $this->supportAt('2026-03-09 10:05');
    expect($this->inTenant($this->tenant, fn () => app(SlaMonitor::class)->run()))->toBe(1)
        // Running again changes nothing and announces nothing.
        ->and($this->inTenant($this->tenant, fn () => app(SlaMonitor::class)->run()))->toBe(0);

    $this->supportAt('2026-03-09 17:30');
    expect($this->inTenant($this->tenant, fn () => app(SlaMonitor::class)->run()))->toBe(1)
        ->and($this->inTenant($this->tenant, fn () => app(SlaMonitor::class)->run()))->toBe(0);

    Event::assertDispatchedTimes(TicketSlaBreached::class, 2);
    Event::assertDispatched(TicketSlaBreached::class, fn ($event) => $event->payload()['target'] === 'first_response');
    Event::assertDispatched(TicketSlaBreached::class, fn ($event) => $event->payload()['target'] === 'resolution');

    $stored = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));
    expect($stored->first_response_breached_at)->not->toBeNull()->and($stored->resolution_breached_at)->not->toBeNull();
});

it('does not breach a ticket that was answered in time, paused, resolved or merged', function () {
    $this->supportAt('2026-03-09 09:00');
    $answered = ($this->open)();
    $waiting = ($this->open)();
    $resolved = ($this->open)();

    $this->supportAt('2026-03-09 09:30');
    $this->inTenant($this->tenant, function () use ($answered, $waiting, $resolved): void {
        $manager = app(TicketManager::class);
        $manager->addMessage($answered, MessageKind::PublicReply, 'On it', $this->owner);
        $manager->changeStatus($waiting, TicketStatus::WaitingCustomer, $this->owner);
        $manager->changeStatus($resolved, TicketStatus::Resolved, $this->owner);
    });

    $this->supportAt('2026-03-09 10:30');
    $count = $this->inTenant($this->tenant, fn () => app(SlaMonitor::class)->run());

    // Only the answered ticket's resolution target (due 17:00) is still running: nothing is due yet.
    expect($count)->toBe(0);
});

it('re-targets unmet clocks when the priority changes', function () {
    $this->supportAt('2026-03-09 09:00');
    $ticket = ($this->open)(['priority' => 'normal']);      // FR 60, resolution 480

    $this->supportAt('2026-03-09 09:20');                    // 20 of 60 minutes used
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->update($ticket, ['priority' => TicketPriority::Urgent->value], $this->owner));

    $after = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));

    // Urgent: 15 minute target, 20 already used: due immediately (now). Resolution: 120 - 20 used = 100 left.
    expect(($this->local)($after->first_response_due_at?->toImmutable()))->toBe('2026-03-09 09:20')
        ->and(($this->local)($after->resolution_due_at?->toImmutable()))->toBe('2026-03-09 11:00');
});

it('restarts the resolution target when a resolved ticket is reopened', function () {
    $this->supportAt('2026-03-09 09:00');
    $ticket = ($this->open)();
    $manager = fn () => app(TicketManager::class);

    $this->supportAt('2026-03-09 11:00');
    $this->inTenant($this->tenant, fn () => $manager()->changeStatus($ticket, TicketStatus::Resolved, $this->owner));
    $this->supportAt('2026-03-10 09:00');
    $this->inTenant($this->tenant, fn () => $manager()->changeStatus($ticket, TicketStatus::Open, $this->owner));

    $reopened = $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));

    expect(($this->local)($reopened->resolution_due_at?->toImmutable()))->toBe('2026-03-10 17:00')
        ->and($reopened->resolved_at)->toBeNull()
        ->and($reopened->resolution_breached_at)->toBeNull();
});

it('rejects an SLA policy with a resolution target shorter than first response', function () {
    expect(fn () => $this->inTenant($this->tenant, fn () => app(SlaConfiguration::class)->savePolicy(null, [
        'name' => 'Bad',
        'targets' => ['normal' => ['first_response' => 120, 'resolution' => 60]],
    ])))->toThrow(ValidationException::class);
});
