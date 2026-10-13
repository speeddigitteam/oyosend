<?php

declare(strict_types=1);

use App\Core\Access\AccessChecker;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\Calendar;
use App\Modules\Support\Models\CannedResponse;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    Mail::fake();
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $this->tenant = $this->supportTenant('sup-http');
    $this->supportAt('2026-03-09 10:00');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->url = fn (string $path = '') => $this->supportUrl($this->tenant, $path);
    $this->reload = fn (Ticket $ticket): Ticket => $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id));
    $this->propsOf = function (string $url): array {
        $props = [];
        $this->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

it('answers 404 for every Support page when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('sup-plain');
    $this->actingAsOwner($plain);

    foreach (['/tickets', '/tickets/create', '/canned', '/kb', '/reports', '/queues', '/sla', '/settings'] as $path) {
        $this->get($this->supportUrl($plain, $path))->assertNotFound();
    }
});

it('forbids a member without the permission and lets a holder in', function () {
    [$nobody] = $this->supportMember($this->tenant, ['support.kb.view']);
    [$agent] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.create']);

    $this->actingAsMember($this->tenant, $nobody)->get(($this->url)('/tickets'))->assertForbidden();
    $this->actingAsMember($this->tenant, $agent)->get(($this->url)('/tickets'))->assertOk();
    $this->actingAsMember($this->tenant, $agent)->get(($this->url)('/reports'))->assertForbidden();
    $this->actingAsMember($this->tenant, $agent)->get(($this->url)('/queues'))->assertForbidden();
    $this->actingAsMember($this->tenant, $agent)->post(($this->url)('/canned'), ['name' => 'x', 'body' => 'y'])->assertForbidden();
});

it('walks the agent workflow over HTTP as the workspace owner', function () {
    $this->actingAsOwner($this->tenant);
    $this->supportAgent($this->tenant, $this->owner);
    $contact = $this->inTenant($this->tenant, fn () => Contact::factory()->create(['first_name' => 'Rima', 'last_name' => null, 'email' => 'rima@example.test']));

    $this->get(($this->url)('/tickets'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Support/Pages/Tickets/Index')
            ->where('canCreate', true)->has('tickets.data', 0));

    $this->get(($this->url)('/tickets/create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Support/Pages/Tickets/Create')
            ->has('form.contacts', 1)->where('form.defaultPriority', 'normal'));

    $this->post(($this->url)('/tickets'), [
        'subject' => 'Printer on fire',
        'body' => 'Smoke is coming out of it.',
        'contact_id' => $contact->id,
        'priority' => 'high',
        'tags' => ['hardware', 'urgent'],
        'attachments' => [UploadedFile::fake()->create('photo.png', 20, 'image/png')],
    ])->assertRedirect();

    $ticket = $this->inTenant($this->tenant, fn () => Ticket::query()->sole());
    expect($ticket->number)->toBe('TKT-00001')
        ->and($ticket->contact_id)->toBe($contact->id)
        ->and($ticket->priority->value)->toBe('high')
        ->and($ticket->created_by)->toBe($this->owner->id)
        // The creator is not the assignee.
        ->and($ticket->owner_id)->toBeNull();

    $this->get(($this->url)("/tickets/{$ticket->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Support/Pages/Tickets/Show')
            ->where('ticket.number', 'TKT-00001')
            ->where('ticket.requester.name', 'Rima')
            ->where('ticket.tags', ['hardware', 'urgent'])
            ->has('messages', 1)
            ->has('messages.0.attachments', 1)
            ->where('abilities.reply', true)
            ->where('abilities.merge', true)
            ->has('events', 1));

    $this->post(($this->url)("/tickets/{$ticket->id}/claim"))->assertRedirect();
    expect(($this->reload)($ticket)->owner_id)->toBe($this->owner->id);

    $this->post(($this->url)("/tickets/{$ticket->id}/messages"), ['kind' => 'internal_note', 'body' => 'Called the vendor.'])->assertRedirect();
    $this->post(($this->url)("/tickets/{$ticket->id}/messages"), [
        'kind' => 'public_reply', 'body' => 'We are on it.', 'status' => 'waiting_customer',
    ])->assertRedirect();

    $after = ($this->reload)($ticket);
    expect($after->status)->toBe(TicketStatus::WaitingCustomer)
        ->and($after->first_responded_at)->not->toBeNull();

    $this->post(($this->url)("/tickets/{$ticket->id}/status"), ['status' => 'resolved'])->assertRedirect();
    expect(($this->reload)($ticket)->status)->toBe(TicketStatus::Resolved);

    $this->put(($this->url)("/tickets/{$ticket->id}"), ['subject' => 'Printer fire (resolved)', 'priority' => 'low', 'tags' => ['hardware']])->assertRedirect();
    expect(($this->reload)($ticket)->subject)->toBe('Printer fire (resolved)');

    $this->get(($this->url)('/tickets?status=all&search=printer'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets.data', 1)->where('tickets.data.0.id', $ticket->id));
    // LIKE wildcards in the search are text.
    $this->get(($this->url)('/tickets?status=all&search=%25'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets.data', 0));
});

it('validates ticket input', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/tickets'), ['subject' => '', 'body' => ''])->assertSessionHasErrors(['subject', 'body']);
    $this->post(($this->url)('/tickets'), ['subject' => 'x', 'body' => 'y', 'priority' => 'critical'])->assertSessionHasErrors('priority');
    $this->post(($this->url)('/tickets'), ['subject' => 'x', 'body' => 'y'])->assertSessionHasErrors('contact_id');
    $this->post(($this->url)('/tickets'), [
        'subject' => 'x', 'body' => 'y', 'requester_email' => 'a@example.test',
        'attachments' => [UploadedFile::fake()->create('run.exe', 5, 'application/octet-stream')],
    ])->assertSessionHasErrors('attachments.0');

    expect($this->inTenant($this->tenant, fn () => Ticket::query()->count()))->toBe(0);
});

it('limits "own" scope agents to the tickets assigned to them plus their queues\' unassigned ones', function () {
    [$userA, $a] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply'], ['support.tickets.view' => 'own', 'support.tickets.reply' => 'own']);
    [, $b] = $this->supportMember($this->tenant, ['support.tickets.view'], ['support.tickets.view' => 'own']);
    $agentA = $this->supportAgent($this->tenant, $a);
    $agentB = $this->supportAgent($this->tenant, $b);
    $queueA = $this->supportQueue($this->tenant, ['name' => 'A team', 'agent_ids' => [$agentA->id]]);
    $queueB = $this->supportQueue($this->tenant, ['name' => 'B team', 'agent_ids' => [$agentB->id]]);

    $mine = $this->supportTicket($this->tenant, ['subject' => 'Mine', 'assignee_id' => $a->id]);
    $theirs = $this->supportTicket($this->tenant, ['subject' => 'Theirs', 'assignee_id' => $b->id]);
    $teamUnassigned = $this->supportTicket($this->tenant, ['subject' => 'Team pool', 'queue_id' => $queueA->id]);
    $otherPool = $this->supportTicket($this->tenant, ['subject' => 'Other pool', 'queue_id' => $queueB->id]);
    $nobody = $this->supportTicket($this->tenant, ['subject' => 'No queue']);

    $this->actingAsMember($this->tenant, $userA);
    $listed = collect(($this->propsOf)(($this->url)('/tickets?status=all'))['tickets']['data'])->pluck('subject')->sort()->values()->all();

    expect($listed)->toBe(['Mine', 'Team pool']);

    $this->get(($this->url)("/tickets/{$mine->id}"))->assertOk();
    $this->get(($this->url)("/tickets/{$teamUnassigned->id}"))->assertOk();
    $this->get(($this->url)("/tickets/{$theirs->id}"))->assertNotFound();
    $this->get(($this->url)("/tickets/{$otherPool->id}"))->assertNotFound();
    $this->get(($this->url)("/tickets/{$nobody->id}"))->assertNotFound();
    $this->post(($this->url)("/tickets/{$theirs->id}/messages"), ['kind' => 'internal_note', 'body' => 'peek'])->assertNotFound();

    // Once someone else takes the pool ticket it leaves the team view for the others.
    $this->inTenant($this->tenant, fn () => app(TicketManager::class)->assign($teamUnassigned, $b, $this->owner));
    $this->get(($this->url)("/tickets/{$teamUnassigned->id}"))->assertNotFound();
});

it('limits department scope to the department of the assignee', function () {
    $sales = $this->inTenant($this->tenant, fn () => Department::query()->create(['name' => 'Sales']));
    $tech = $this->inTenant($this->tenant, fn () => Department::query()->create(['name' => 'Tech']));
    [$viewer, $viewerMembership] = $this->supportMember($this->tenant, ['support.tickets.view'], ['support.tickets.view' => 'department']);
    [, $salesAgentMembership] = $this->supportMember($this->tenant, ['support.tickets.view']);
    [, $techAgentMembership] = $this->supportMember($this->tenant, ['support.tickets.view']);
    $this->inCentral(function () use ($sales, $tech, $viewerMembership, $salesAgentMembership, $techAgentMembership): void {
        TenantMembership::withoutTenancy()->whereKey($viewerMembership->id)->update(['department_id' => $sales->id]);
        TenantMembership::withoutTenancy()->whereKey($salesAgentMembership->id)->update(['department_id' => $sales->id]);
        TenantMembership::withoutTenancy()->whereKey($techAgentMembership->id)->update(['department_id' => $tech->id]);
    });
    app(AccessChecker::class)->forgetMemberships();
    $this->supportAgent($this->tenant, $salesAgentMembership);
    $this->supportAgent($this->tenant, $techAgentMembership);

    $salesTicket = $this->supportTicket($this->tenant, ['subject' => 'Sales one', 'assignee_id' => $salesAgentMembership->id]);
    $techTicket = $this->supportTicket($this->tenant, ['subject' => 'Tech one', 'assignee_id' => $techAgentMembership->id]);

    $this->actingAsMember($this->tenant, $viewer);

    $this->get(($this->url)("/tickets/{$salesTicket->id}"))->assertOk();
    $this->get(($this->url)("/tickets/{$techTicket->id}"))->assertNotFound();
});

it('only lets someone holding the assign permission for all records hand a ticket to another person', function () {
    [$lead, $leadMembership] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.assign'], ['support.tickets.assign' => 'all']);
    [$junior, $juniorMembership] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.assign'], ['support.tickets.assign' => 'own', 'support.tickets.view' => 'all']);
    $this->supportAgent($this->tenant, $leadMembership);
    $this->supportAgent($this->tenant, $juniorMembership);
    $ticket = $this->supportTicket($this->tenant, ['assignee_id' => $juniorMembership->id]);

    // The junior owns the ticket: may keep it, may not give it away.
    $this->actingAsMember($this->tenant, $junior)
        ->post(($this->url)("/tickets/{$ticket->id}/assign"), ['assignee_id' => $leadMembership->id])->assertForbidden();
    $this->post(($this->url)("/tickets/{$ticket->id}/assign"), ['auto' => true])->assertForbidden();

    $this->actingAsMember($this->tenant, $lead)
        ->post(($this->url)("/tickets/{$ticket->id}/assign"), ['assignee_id' => $leadMembership->id])->assertRedirect();
    expect(($this->reload)($ticket)->owner_id)->toBe($leadMembership->id);
});

it('keeps attachments private: only people who may see the ticket can download, through an authorized route', function () {
    [$viewer] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply']);
    [$outsider] = $this->supportMember($this->tenant, ['support.kb.view']);
    $ticket = $this->supportTicket($this->tenant);
    $other = $this->supportTicket($this->tenant, ['subject' => 'Other']);

    $message = $this->inTenant($this->tenant, fn () => app(TicketManager::class)->addMessage(
        $ticket, MessageKind::InternalNote, 'See attached', $this->owner,
        [UploadedFile::fake()->createWithContent('secret.txt', 'TOP SECRET CONTENT')],
    ));
    $attachment = $this->inTenant($this->tenant, fn () => Attachment::query()->where('message_id', $message->id)->sole());

    // The stored path is tenant-rooted, hidden from serialization, and never in a page prop.
    $path = $attachment->path;
    expect($path)->toStartWith('support/attachments/')
        ->and(app(TenantStorage::class))->toBeInstanceOf(TenantStorage::class)
        ->and(json_encode($attachment))->not->toContain($path);

    $this->actingAsMember($this->tenant, $viewer);
    expect(json_encode(($this->propsOf)(($this->url)("/tickets/{$ticket->id}"))))->not->toContain($path);

    $download = $this->get(($this->url)("/tickets/{$ticket->id}/attachments/{$attachment->id}"));
    $download->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('attachment; filename="secret.txt"')
        ->and($download->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($download->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store')
        ->and($download->getContent())->toBe('TOP SECRET CONTENT');

    // Wrong ticket for the attachment id, and a member without ticket permission.
    $this->get(($this->url)("/tickets/{$other->id}/attachments/{$attachment->id}"))->assertNotFound();
    $this->actingAsMember($this->tenant, $outsider)->get(($this->url)("/tickets/{$ticket->id}/attachments/{$attachment->id}"))->assertForbidden();
    // No public URL exists for the file: a guest is sent to sign in.
    $this->startNewBrowserSession()->get(($this->url)("/tickets/{$ticket->id}/attachments/{$attachment->id}"))->assertRedirect();
});

it('stores attachments under the owning workspace only', function () {
    $other = $this->supportTenant('sup-http-other');
    $ticket = $this->supportTicket($this->tenant);
    $message = $this->inTenant($this->tenant, fn () => app(TicketManager::class)->addMessage(
        $ticket, MessageKind::InternalNote, 'File', $this->owner, [UploadedFile::fake()->create('a.pdf', 4, 'application/pdf')],
    ));
    $attachment = $this->inTenant($this->tenant, fn () => Attachment::query()->where('message_id', $message->id)->sole());

    $disk = Storage::disk((string) config('tenancy.filesystem.disk', 'local'));
    $root = trim((string) config('tenancy.filesystem.root', 'tenants'), '/');

    expect($disk->exists($root.'/'.$this->tenant->id.'/'.$attachment->path))->toBeTrue()
        ->and($disk->exists($root.'/'.$other->id.'/'.$attachment->path))->toBeFalse();
});

it('fills canned response placeholders on the server and rejects unknown ones when saving', function () {
    $this->actingAsOwner($this->tenant);
    $ticket = $this->supportTicket($this->tenant, ['subject' => 'Refund', 'requester_name' => 'Nadia Karim']);

    $this->post(($this->url)('/canned'), [
        'name' => 'Greeting',
        'body' => 'Hello {{ requester.name }}, this is {{agent.name}} from {{ workspace.name }} about {{ticket.number}}.',
    ])->assertRedirect();
    $canned = $this->inTenant($this->tenant, fn () => CannedResponse::query()->sole());

    $this->get(($this->url)("/tickets/{$ticket->id}/canned/{$canned->id}"))->assertOk()
        ->assertJsonPath('body', 'Hello Nadia Karim, this is '.$this->ownerOf($this->tenant)->name.' from Support Workspace about TKT-00001.');

    $this->post(($this->url)('/canned'), ['name' => 'Evil', 'body' => 'Dear {{ user.password }}'])->assertSessionHasErrors('body');
    $this->post(($this->url)('/canned'), ['name' => 'Broken', 'body' => 'Dear {{ requester.name'])->assertSessionHasErrors('body');
    $this->post(($this->url)('/canned'), ['name' => 'Greeting', 'body' => 'Duplicate name'])->assertSessionHasErrors('name');

    expect($this->inTenant($this->tenant, fn () => CannedResponse::query()->count()))->toBe(1);
});

it('runs the knowledge base lifecycle with versions and keeps drafts from readers', function () {
    [$reader] = $this->supportMember($this->tenant, ['support.kb.view']);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/kb/categories'), ['name' => 'Accounts'])->assertRedirect();
    $this->post(($this->url)('/kb/articles'), ['title' => 'Reset your password', 'summary' => 'How to', 'body' => 'Step one.'])->assertRedirect();
    $article = $this->inTenant($this->tenant, fn () => KbArticle::query()->sole());
    expect($article->status->value)->toBe('draft')->and($article->slug)->toBe('reset-your-password')->and($article->current_version)->toBe(1);

    $this->put(($this->url)("/kb/articles/{$article->id}"), ['title' => 'Reset your password', 'summary' => 'How to', 'body' => 'Step one. Step two.'])->assertRedirect();
    $this->put(($this->url)("/kb/articles/{$article->id}"), ['title' => 'Reset your password', 'summary' => 'How to', 'body' => 'Step one. Step two.'])->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $article->fresh()->current_version))->toBe(2);

    // A reader sees no drafts.
    $this->actingAsMember($this->tenant, $reader);
    $this->get(($this->url)('/kb'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('articles.data', 0)->where('canManage', false));
    $this->get(($this->url)("/kb/articles/{$article->id}"))->assertNotFound();
    $this->post(($this->url)("/kb/articles/{$article->id}/publish"))->assertForbidden();

    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)("/kb/articles/{$article->id}/publish"))->assertRedirect();
    $this->post(($this->url)("/kb/articles/{$article->id}/versions/1/restore"))->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $article->fresh()))->current_version->toBe(3)->body->toBe('Step one.');

    $this->actingAsMember($this->tenant, $reader);
    $this->get(($this->url)('/kb?search=password'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('articles.data', 1));
    $this->get(($this->url)('/kb?search=%25'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('articles.data', 0));

    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)("/kb/articles/{$article->id}/archive"))->assertRedirect();
    $this->actingAsMember($this->tenant, $reader)->get(($this->url)("/kb/articles/{$article->id}"))->assertNotFound();
});

it('serves the report with exact figures', function () {
    $this->actingAsOwner($this->tenant);
    $this->supportAgent($this->tenant, $this->owner);
    $tickets = collect(range(1, 3))->map(fn () => $this->supportTicket($this->tenant));

    $this->supportAt('2026-03-09 10:30');
    $this->inTenant($this->tenant, function () use ($tickets): void {
        app(TicketManager::class)->addMessage($tickets[0], MessageKind::PublicReply, 'Hi', $this->owner, thenStatus: TicketStatus::Resolved);
    });

    $this->get(($this->url)('/reports?from=2026-03-01&to=2026-03-31'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Support/Pages/Reports/Index')
            ->where('report.volume.created', 3)
            ->where('report.volume.resolved', 1)
            ->where('report.backlog.total', 2)
            ->where('report.backlog.unassigned', 2)
            ->where('report.times.firstResponseAverage', 30)
            ->where('report.times.resolutionAverage', 30));

    $this->get(($this->url)('/reports?from=2026-03-31&to=2026-03-01'))->assertSessionHasErrors('to');
});

it('configures queues, agents, rules, SLA and settings through their pages', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/agents'), ['membership_id' => $this->owner->id, 'max_open_tickets' => 5])->assertRedirect();
    $this->post(($this->url)('/agents'), ['membership_id' => str_repeat('0', 26)])->assertSessionHasErrors('membership_id');
    $agent = $this->inTenant($this->tenant, fn () => Agent::query()->sole());

    $this->post(($this->url)('/queues'), ['name' => 'Billing', 'assignment_mode' => 'round_robin', 'agent_ids' => [$agent->id]])->assertRedirect();
    $queue = $this->inTenant($this->tenant, fn () => Queue::query()->sole());
    $this->post(($this->url)('/categories'), ['name' => 'Refunds', 'queue_id' => $queue->id])->assertRedirect();
    $this->post(($this->url)('/rules'), ['name' => 'Invoices', 'match_keyword' => 'invoice', 'queue_id' => $queue->id, 'position' => 1])->assertRedirect();

    $this->post(($this->url)('/sla/calendars'), ['name' => 'Office', 'hours' => ['1' => [['09:00', '17:00']], '2' => [['09:00', '17:00']]], 'holidays' => ['2026-12-16']])->assertRedirect();
    $this->post(($this->url)('/sla/calendars'), ['name' => 'Bad', 'hours' => ['1' => [['17:00', '09:00']]]])->assertSessionHasErrors('hours');
    $calendar = $this->inTenant($this->tenant, fn () => Calendar::query()->sole());
    $this->post(($this->url)('/sla/policies'), [
        'name' => 'Standard', 'calendar_id' => $calendar->id,
        'targets' => ['normal' => ['first_response' => 60, 'resolution' => 480]],
    ])->assertRedirect();
    $this->post(($this->url)('/sla/policies'), ['name' => 'Empty', 'targets' => []])->assertSessionHasErrors('targets');
    $policy = $this->inTenant($this->tenant, fn () => SlaPolicy::query()->sole());

    $this->put(($this->url)('/settings'), [
        'ticket_prefix' => 'SUP-', 'number_padding' => 4, 'default_priority' => 'normal', 'default_queue_id' => $queue->id,
        'default_sla_policy_id' => $policy->id, 'csat_enabled' => true, 'csat_expiry_days' => 7, 'csat_send_email' => false, 'auto_close_days' => 10,
    ])->assertRedirect();

    $this->get(($this->url)('/queues'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('queues', 1)->has('agents', 1)->has('categories', 1)->has('rules', 1)
            ->where('queues.0.agentIds', [$agent->id])->where('agents.0.maxOpenTickets', 5));
    $this->get(($this->url)('/sla'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('policies', 1)->has('calendars', 1)->where('calendars.0.holidays', ['2026-12-16']));
    $this->get(($this->url)('/settings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.ticketPrefix', 'SUP-')->where('settings.autoCloseDays', 10));

    expect($this->supportTicket($this->tenant)->number)->toBe('SUP-0001');
});

it('lists Support in the sidebar and as a dashboard widget for the entitled workspace', function () {
    $this->supportTicket($this->tenant);
    $this->actingAsOwner($this->tenant);

    $sidebar = [];
    $widgets = [];
    $this->get($this->tenantUrl($this->tenant, '/'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$sidebar, &$widgets): void {
            $props = $page->toArray()['props'];

            foreach ($props['navigation'] as $section) {
                $sidebar[$section['key']] = array_column($section['items'], 'key');
            }

            $widgets = collect($props['widgets'])->keyBy('key')->all();
        });

    expect($sidebar['apps'])->toContain('supportTickets', 'supportKb', 'supportCanned', 'supportReports', 'supportQueues', 'supportSla', 'supportSettings')
        ->and($widgets)->toHaveKey('support.overview')
        ->and($widgets['support.overview']['data'])->toMatchArray(['open' => 1, 'unassigned' => 1, 'mine' => 0, 'waitingOnCustomer' => 0, 'breached' => 0]);

    // A workspace without the app gets neither.
    $plain = $this->createTenant('sup-plain-nav');
    $this->actingAsOwner($plain)->get($this->tenantUrl($plain, '/'))->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect(json_encode($props['navigation']))->not->toContain('supportTickets')
                ->and(collect($props['widgets'])->pluck('key')->all())->not->toContain('support.overview');
        });
});

it('keeps a message body as inert text', function () {
    $this->actingAsOwner($this->tenant);
    $ticket = $this->supportTicket($this->tenant);
    $payload = '<script>alert(1)</script> {{ requester.name }}';

    $this->post(($this->url)("/tickets/{$ticket->id}/messages"), ['kind' => 'internal_note', 'body' => $payload])->assertRedirect();

    $stored = $this->inTenant($this->tenant, fn () => Message::query()->where('ticket_id', $ticket->id)->where('kind', MessageKind::InternalNote)->sole());

    // Stored verbatim (the page escapes it); placeholders are only filled when a canned response is previewed.
    expect($stored->body)->toBe($payload);
});

it('offers companies to the assignment rules and refuses one from another workspace', function () {
    $this->actingAsOwner($this->tenant);
    $this->supportAgent($this->tenant, $this->owner);
    $queue = $this->supportQueue($this->tenant);
    $acme = $this->inTenant($this->tenant, fn () => Company::factory()->create(['name' => 'Acme Ltd']));
    $other = $this->createTenant('sup-other');
    $foreign = $this->inTenant($other, fn () => Company::factory()->create(['name' => 'Foreign Co']));

    $this->post(($this->url)('/rules'), ['name' => 'Acme', 'match_company_id' => $acme->id, 'queue_id' => $queue->id])->assertRedirect();
    $this->post(($this->url)('/rules'), ['name' => 'Foreign', 'match_company_id' => $foreign->id, 'queue_id' => $queue->id])
        ->assertSessionHasErrors('match_company_id');

    $this->get(($this->url)('/queues'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('companies', 1)->where('companies.0.name', 'Acme Ltd')
            ->has('rules', 1)->where('rules.0.matchCompanyId', $acme->id));
});

it('names the member and queue on assignment and queue history entries', function () {
    $this->actingAsOwner($this->tenant);
    $agent = $this->supportAgent($this->tenant, $this->owner);
    $queue = $this->supportQueue($this->tenant, ['name' => 'Billing desk', 'agent_ids' => [$agent->id]]);
    $ticket = $this->supportTicket($this->tenant);

    $this->post(($this->url)("/tickets/{$ticket->id}/claim"))->assertRedirect();
    $this->put(($this->url)("/tickets/{$ticket->id}"), ['queue_id' => $queue->id])->assertRedirect();

    $events = collect(($this->propsOf)(($this->url)("/tickets/{$ticket->id}"))['events'])->keyBy('kind');

    expect($events['assigned']['data']['toName'])->toBe($this->ownerOf($this->tenant)->name)
        ->and($events['assigned']['data']['to'])->toBe($this->owner->id)
        ->and($events['queue_changed']['data']['toName'])->toBe('Billing desk');
});

it('lets someone who can edit change the contact and company of a ticket from the ticket page', function () {
    $this->actingAsOwner($this->tenant);
    $ticket = $this->supportTicket($this->tenant);
    $contact = $this->inTenant($this->tenant, fn () => Contact::factory()->create(['first_name' => 'Rima', 'last_name' => null]));
    $company = $this->inTenant($this->tenant, fn () => Company::factory()->create(['name' => 'Acme Ltd']));

    $props = ($this->propsOf)(($this->url)("/tickets/{$ticket->id}"));
    expect($props['options']['contacts'])->toHaveCount(1)
        ->and($props['options']['companies'])->toHaveCount(1);

    $this->put(($this->url)("/tickets/{$ticket->id}"), ['contact_id' => $contact->id, 'company_id' => $company->id])->assertRedirect();

    $after = ($this->reload)($ticket);
    expect($after->contact_id)->toBe($contact->id)
        ->and($after->company_id)->toBe($company->id);

    $this->get(($this->url)("/tickets/{$ticket->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('ticket.requester.companyName', 'Acme Ltd'));

    // A member who can only read the ticket is not sent the customer directory.
    [$reader] = $this->supportMember($this->tenant, ['support.tickets.view']);
    $this->actingAsMember($this->tenant, $reader)->get(($this->url)("/tickets/{$ticket->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('abilities.edit', false)->where('options.contacts', [])->where('options.companies', []));
});
