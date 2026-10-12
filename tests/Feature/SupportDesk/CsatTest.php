<?php

declare(strict_types=1);

use App\Modules\Support\Actions\SupportMaintenance;
use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Actions\SurveyManager;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\CsatSubmitted;
use App\Modules\Support\Mail\CsatInvitationMail;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    Mail::fake();
    $this->tenant = $this->supportTenant('sup-csat');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->supportAt('2026-03-09 10:00');
    $this->resolve = function (?Ticket $ticket = null): array {
        $ticket ??= $this->supportTicket($this->tenant, ['requester_email' => 'nadia@example.test']);
        $token = $this->inTenant($this->tenant, function () use ($ticket): ?string {
            app(TicketManager::class)->changeStatus($ticket, TicketStatus::Resolved, $this->owner);

            return app(SurveyManager::class)->takeIssuedToken($ticket->id);
        });

        return [$ticket, $token];
    };
    $this->survey = fn (Ticket $ticket) => $this->inTenant($this->tenant, fn () => CsatSurvey::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail());
});

it('issues a 256-bit token on resolve and stores only its hash', function () {
    [$ticket, $token] = ($this->resolve)();

    expect($token)->toMatch('/^[a-f0-9]{64}$/');

    $row = DB::table('support_csat_surveys')->where('ticket_id', $ticket->id)->first();
    $stored = json_encode($row);

    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->status)->toBe('pending')
        ->and($stored)->not->toContain($token);

    // The history and audit trail do not carry the token either.
    expect(json_encode(DB::table('support_ticket_events')->get()))->not->toContain($token)
        ->and(json_encode($this->auditTrail($this->tenant)))->not->toContain($token);
});

it('emails the single-use link to the requester through an encrypted queued mailable', function () {
    [$ticket, $token] = ($this->resolve)();

    Mail::assertQueued(CsatInvitationMail::class, fn (CsatInvitationMail $mail) => $mail->hasTo('nadia@example.test'));

    $html = $this->inTenant($this->tenant, fn () => (new CsatInvitationMail($ticket->id, $ticket->number, $token))->render());

    expect($html)->toContain('/support-survey/'.$token)->toContain($ticket->number);
    expect(new CsatInvitationMail($ticket->id, $ticket->number, $token))->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('does not request a survey when satisfaction surveys are switched off', function () {
    $this->inTenant($this->tenant, fn () => app(SupportSettings::class)->update(['csat_enabled' => false]));

    [$ticket, $token] = ($this->resolve)();

    expect($token)->toBeNull()
        ->and($this->inTenant($this->tenant, fn () => CsatSurvey::query()->count()))->toBe(0);
    Mail::assertNothingQueued();
});

it('shows the survey to a signed-out visitor holding the token, without a session', function () {
    [$ticket, $token] = ($this->resolve)();

    $response = $this->get($this->tenantUrl($this->tenant, "/support-survey/{$token}"))
        ->assertOk()
        ->assertSee($ticket->number)
        ->assertSee($ticket->subject)
        ->assertSee('Support Workspace')
        ->assertSee('name="rating"', false);

    // Stateless: no session cookie is set, and the page carries no CSRF token.
    expect($response->headers->getCookies())->toBe([])
        ->and($response->getContent())->not->toContain('_token');
});

it('accepts one answer per link and then answers 404', function () {
    Event::fake([CsatSubmitted::class]);
    [$ticket, $token] = ($this->resolve)();
    $url = $this->tenantUrl($this->tenant, "/support-survey/{$token}");

    $this->post($url, ['rating' => 5, 'comment' => 'Fast and kind.'])->assertOk()->assertSee('Thank you for your feedback');

    $survey = ($this->survey)($ticket);
    expect($survey->status)->toBe(SurveyStatus::Answered)
        ->and($survey->rating)->toBe(5)
        ->and($survey->comment)->toBe('Fast and kind.')
        ->and($survey->token_hash)->toBeNull();

    // Single use: neither a second answer nor viewing works any more, and nothing changes.
    $this->post($url, ['rating' => 1, 'comment' => 'Changed my mind'])->assertNotFound();
    $this->get($url)->assertNotFound();

    expect(($this->survey)($ticket)->rating)->toBe(5);
    Event::assertDispatchedTimes(CsatSubmitted::class, 1);
    Event::assertDispatched(CsatSubmitted::class, fn ($event) => $event->payload()['rating'] === 5 && $event->payload()['ticket_id'] === $ticket->id);
});

it('counts two simultaneous submissions only once', function () {
    [$ticket, $token] = ($this->resolve)();

    $first = $this->inTenant($this->tenant, fn () => app(SurveyManager::class)->answer($token, 4, null));
    $second = null;

    try {
        $second = $this->inTenant($this->tenant, fn () => app(SurveyManager::class)->answer($token, 2, null));
    } catch (NotFoundHttpException) {
        // the link was already used
    }

    expect($first->rating)->toBe(4)->and($second)->toBeNull()
        ->and($this->inTenant($this->tenant, fn () => CsatSurvey::query()->where('status', SurveyStatus::Answered)->count()))->toBe(1);
});

it('validates the rating and keeps the link alive after a failed attempt', function () {
    [, $token] = ($this->resolve)();
    $url = $this->tenantUrl($this->tenant, "/support-survey/{$token}");

    $this->post($url, ['rating' => 6])->assertStatus(422)->assertSee('Choose a rating from 1 to 5.');
    $this->post($url, [])->assertStatus(422);
    $this->post($url, ['rating' => 3, 'comment' => str_repeat('x', 2001)])->assertStatus(422);
    $this->post($url, ['rating' => 'five'])->assertStatus(422);

    // The failed attempts did not burn the link.
    $this->post($url, ['rating' => 3])->assertOk()->assertSee('Thank you for your feedback');
});

it('answers 404 for a malformed, unknown or expired token', function () {
    [$ticket, $token] = ($this->resolve)();

    $this->get($this->tenantUrl($this->tenant, '/support-survey/not-a-token'))->assertNotFound();
    $this->get($this->tenantUrl($this->tenant, '/support-survey/'.str_repeat('a', 64)))->assertNotFound();

    $this->supportAt('2026-04-30 10:00');   // beyond the 14 day expiry

    $this->get($this->tenantUrl($this->tenant, "/support-survey/{$token}"))->assertNotFound();
    $this->post($this->tenantUrl($this->tenant, "/support-survey/{$token}"), ['rating' => 5])->assertNotFound();

    $this->inTenant($this->tenant, fn () => app(SupportMaintenance::class)->run());
    $survey = ($this->survey)($ticket);

    expect($survey->status)->toBe(SurveyStatus::Expired)->and($survey->token_hash)->toBeNull();
});

it('supersedes the older link when a new survey is requested', function () {
    [$ticket, $first] = ($this->resolve)();

    $second = $this->inTenant($this->tenant, function () use ($ticket): ?string {
        $manager = app(SurveyManager::class);
        $manager->request(Ticket::query()->findOrFail($ticket->id), $this->owner, force: true);

        return $manager->takeIssuedToken($ticket->id);
    });

    $this->get($this->tenantUrl($this->tenant, "/support-survey/{$first}"))->assertNotFound();
    $this->get($this->tenantUrl($this->tenant, "/support-survey/{$second}"))->assertOk();
});

it('never serves another workspace\'s survey on this host', function () {
    $other = $this->supportTenant('sup-csat-other');
    [, $token] = ($this->resolve)();

    $this->get($this->tenantUrl($other, "/support-survey/{$token}"))->assertNotFound();
    $this->post($this->tenantUrl($other, "/support-survey/{$token}"), ['rating' => 5])->assertNotFound();
});

it('is not reachable when the workspace is not entitled to the app', function () {
    [, $token] = ($this->resolve)();
    $plain = $this->createTenant('sup-csat-plain');

    $this->get($this->tenantUrl($plain, "/support-survey/{$token}"))->assertNotFound();
    $this->post($this->tenantUrl($plain, "/support-survey/{$token}"), ['rating' => 5])->assertNotFound();
});

it('is in the stateless callback group: no web group, no sign-in, throttled', function () {
    $routes = app('router')->getRoutes();
    $store = $routes->getByName('support.csat.store');
    $show = $routes->getByName('support.csat.show');

    expect($store->gatherMiddleware())->toContain('tenant.callback')->toContain('entitled:support')->toContain('throttle:10,1')
        ->not->toContain('web')->not->toContain('auth:web')
        ->and($show->gatherMiddleware())->toContain('tenant.callback')->toContain('entitled:support')
        ->not->toContain('web')->not->toContain('auth:web');
});
