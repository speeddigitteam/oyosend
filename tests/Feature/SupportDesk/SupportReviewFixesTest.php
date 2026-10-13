<?php

declare(strict_types=1);

use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Actions\SurveyManager;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Actions\TicketMerger;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    Mail::fake();
    $this->tenant = $this->supportTenant('sup-fix2');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->supportAt('2026-03-09 10:00');
    $this->url = fn (string $path = '') => $this->supportUrl($this->tenant, $path);
    $this->reload = fn (Ticket $ticket): Ticket => $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));
    $this->resolved = function (array $overrides = []): Ticket {
        $ticket = $this->supportTicket($this->tenant, $overrides);
        $this->inTenant($this->tenant, function () use ($ticket): void {
            app(TicketManager::class)->changeStatus($ticket, TicketStatus::Resolved, $this->owner);
            app(SurveyManager::class)->takeIssuedToken($ticket->id);
        });

        return $ticket;
    };
});

it('does not let an agent without the resolve right reopen a finished ticket by logging a customer reply', function () {
    $ticket = ($this->resolved)();
    [$agent] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply'], [
        'support.tickets.view' => 'all', 'support.tickets.reply' => 'all',
    ]);

    $this->actingAsMember($this->tenant, $agent)
        ->post(($this->url)("/tickets/{$ticket->id}/messages"), ['kind' => 'customer_reply', 'body' => 'Still broken.'])
        ->assertForbidden();

    expect(($this->reload)($ticket)->status)->toBe(TicketStatus::Resolved)
        ->and($this->inTenant($this->tenant, fn () => $ticket->messages()->where('body', 'Still broken.')->count()))->toBe(0);

    // An ordinary reply and a customer reply on an open ticket are still allowed.
    $open = $this->supportTicket($this->tenant, ['subject' => 'Open one']);
    $this->actingAsMember($this->tenant, $agent)
        ->post(($this->url)("/tickets/{$open->id}/messages"), ['kind' => 'customer_reply', 'body' => 'Phoned in.'])
        ->assertRedirect();
});

it('lets a resolver log a customer reply, reopens the ticket and keeps the agent in the history', function () {
    $ticket = ($this->resolved)();
    [$lead, $membership] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply', 'support.tickets.resolve'], [
        'support.tickets.view' => 'all', 'support.tickets.reply' => 'all', 'support.tickets.resolve' => 'all',
    ]);

    $this->actingAsMember($this->tenant, $lead)
        ->post(($this->url)("/tickets/{$ticket->id}/messages"), ['kind' => 'customer_reply', 'body' => 'Still broken.'])
        ->assertRedirect();

    $event = $this->inTenant($this->tenant, fn () => TicketEvent::query()->where('ticket_id', $ticket->id)->where('kind', TicketEventKind::CustomerReplied)->sole());

    expect(($this->reload)($ticket)->status)->toBe(TicketStatus::Open)
        ->and($event->actor_id)->toBe($membership->id)
        ->and($event->data['logged_by'] ?? null)->toBe($membership->id);
});

it('copies the first response across a merge only for the same requester and only when it is earlier', function () {
    $target = $this->supportTicket($this->tenant, ['subject' => 'Target', 'requester_email' => 'a@example.test']);
    $stranger = $this->supportTicket($this->tenant, ['subject' => 'Stranger', 'requester_email' => 'b@example.test']);
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->addMessage($stranger, MessageKind::PublicReply, 'Hello B', $this->owner));

    $this->supportAt('2026-03-09 11:00');
    $this->inTenant($this->tenant, fn () => app(TicketMerger::class)->merge($stranger, $target, $this->owner, null, true));

    expect(($this->reload)($target)->first_responded_at)->toBeNull();

    // The same requester: the earlier reply carries over.
    $same = $this->supportTicket($this->tenant, ['subject' => 'Same', 'requester_email' => 'a@example.test']);
    $this->supportAt('2026-03-09 11:30');
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->addMessage($same, MessageKind::PublicReply, 'Hello A', $this->owner));
    $this->supportAt('2026-03-09 12:00');
    $this->inTenant($this->tenant, fn () => app(TicketMerger::class)->merge($same, $target, $this->owner, null));

    expect(($this->reload)($target)->first_responded_at?->equalTo(CarbonImmutable::parse('2026-03-09 11:30', 'Asia/Dhaka')))->toBeTrue();
});

it('does not flash the survey link to the agent when it was emailed, and flashes it when it was not', function () {
    $ticket = ($this->resolved)(['requester_email' => 'nadia@example.test']);
    $this->actingAsOwner($this->tenant);

    $this->supportAt('2026-03-09 10:30');
    $this->post(($this->url)("/tickets/{$ticket->id}/survey"))
        ->assertRedirect()
        ->assertSessionMissing('inertia.flash_data.surveyInvitation');

    $this->inTenant($this->tenant, fn () => app(SupportSettings::class)->update(['csat_send_email' => false]));
    $this->supportAt('2026-03-09 11:00');
    $this->post(($this->url)("/tickets/{$ticket->id}/survey"))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.surveyInvitation.ticketId', $ticket->id);
});

it('allows one new survey per ticket per ten minutes and audits the forced request', function () {
    $ticket = ($this->resolved)();
    $this->actingAsOwner($this->tenant);

    // The survey requested by resolving the ticket counts: a second one right away is refused.
    $this->post(($this->url)("/tickets/{$ticket->id}/survey"))->assertSessionHasErrors('ticket');
    expect($this->inTenant($this->tenant, fn () => CsatSurvey::query()->where('ticket_id', $ticket->id)->count()))->toBe(1);

    $this->supportAt('2026-03-09 10:11');
    $this->post(($this->url)("/tickets/{$ticket->id}/survey"))->assertSessionHasNoErrors()->assertRedirect();
    $this->post(($this->url)("/tickets/{$ticket->id}/survey"))->assertSessionHasErrors('ticket');

    $surveys = $this->inTenant($this->tenant, fn () => CsatSurvey::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->get());
    expect($surveys)->toHaveCount(2)
        ->and($surveys[0]->status)->toBe(SurveyStatus::Expired)
        ->and($surveys[1]->status)->toBe(SurveyStatus::Pending)
        ->and(json_encode($this->auditTrail($this->tenant)))->toContain('support.csat.forced');
});

it('sends hardening headers with the public survey page', function () {
    $ticket = ($this->resolved)();
    $token = $this->inTenant($this->tenant, function () use ($ticket): string {
        $manager = app(SurveyManager::class);
        $manager->request(Ticket::query()->findOrFail($ticket->id), $this->owner, force: true);

        return (string) $manager->takeIssuedToken($ticket->id);
    });
    $url = $this->tenantUrl($this->tenant, "/support-survey/{$token}");
    $check = function ($response): void {
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    };

    $check($this->get($url)->assertOk());
    $check($this->post($url, ['rating' => 9])->assertStatus(422));
    $check($this->post($url, ['rating' => 5])->assertOk());
    $check($this->get($url)->assertNotFound());
});

it('only lets a holder of the assign right for every ticket enrol or change agents', function () {
    [$manager, $managerMembership] = $this->supportMember($this->tenant, ['support.queues.manage', 'support.tickets.view', 'support.tickets.assign'], [
        'support.tickets.view' => 'all', 'support.tickets.assign' => 'own',
    ]);
    [, $colleague] = $this->supportMember($this->tenant, ['support.tickets.view']);

    $this->actingAsMember($this->tenant, $manager)
        ->post(($this->url)('/agents'), ['membership_id' => $managerMembership->id])->assertForbidden();
    $this->actingAsMember($this->tenant, $manager)
        ->post(($this->url)('/agents'), ['membership_id' => $colleague->id])->assertForbidden();
    expect($this->inTenant($this->tenant, fn () => Agent::query()->count()))->toBe(0);

    $agent = $this->supportAgent($this->tenant, $colleague);
    $this->actingAsMember($this->tenant, $manager)
        ->put(($this->url)("/agents/{$agent->id}"), ['is_available' => false])->assertForbidden();

    [$lead] = $this->supportMember($this->tenant, ['support.queues.manage', 'support.tickets.assign'], ['support.tickets.assign' => 'all']);
    $this->actingAsMember($this->tenant, $lead)
        ->put(($this->url)("/agents/{$agent->id}"), ['is_available' => false])->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => Agent::query()->findOrFail($agent->id))->is_available)->toBeFalse();
});
