<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\Models\AuditLog;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->admin = $this->addMember($this->acme, Role::ADMIN);

    $this->logger = fn (): AuditLogger => app(AuditLogger::class);

    /** The events on a tenant's audit page, newest first, as an admin of that tenant sees them. */
    $this->pageEvents = function ($tenant, string $query = ''): array {
        $events = [];

        $this->startNewBrowserSession()
            ->actingAsOwner($tenant)
            ->get($this->tenantUrl($tenant, 'audit-log'.$query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$events): void {
                $events = array_column($page->toArray()['props']['entries']['data'], 'event');
            });

        return $events;
    };
});

describe('what gets recorded', function () {
    it('records the whole life of a member, from invitation to sign-out', function () {
        $employee = $this->roleOf($this->acme, Role::EMPLOYEE);
        $manager = $this->roleOf($this->acme, Role::MANAGER);
        $start = $this->auditTrail($this->acme)->count();

        // Invited by the admin ...
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/invitations', ['email' => 'nadia@example.test', 'role_id' => $employee->id]);

        $html = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        preg_match('#/invitations/([A-Za-z0-9]{64})#', (string) $html, $match);

        // ... joins ...
        $this->startNewBrowserSession();
        $this->post("http://acme.localhost/invitations/{$match[1]}", [
            'name' => 'Nadia Islam', 'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
        ])->assertRedirect('http://acme.localhost');
        $this->post('http://acme.localhost/logout');

        // ... mistypes her password, then signs in ...
        $this->post('http://acme.localhost/login', ['email' => 'nadia@example.test', 'password' => 'oops']);
        $this->post('http://acme.localhost/login', ['email' => 'nadia@example.test', 'password' => 'a-good-password']);
        $this->post('http://acme.localhost/logout');

        // ... and is promoted by the admin.
        $nadia = TenantMembership::forTenant($this->acme)->whereHas('user', fn ($user) => $user->where('email', 'nadia@example.test'))->sole();

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$nadia->id}", ['role_id' => $manager->id]);

        $trail = $this->auditTrail($this->acme)->slice($start)->values();

        expect($trail->pluck('event')->all())->toBe([
            'member.invited',
            'tenant_membership.created',
            'member.joined',
            'auth.login',
            'auth.logout',
            'auth.login_failed',
            'auth.login',
            'auth.logout',
            'member.role_changed',
        ]);

        $byEvent = $trail->groupBy('event');

        expect($byEvent['member.invited'][0]->actor_label)->toBe($this->admin->email)
            ->and($byEvent['member.joined'][0]->actor_label)->toBe('nadia@example.test')
            ->and($byEvent['member.joined'][0]->properties)->toBe(['role' => 'Employee'])
            ->and($byEvent['auth.login'][0]->properties)->toBe(['method' => 'invitation'])
            ->and($byEvent['auth.login'][1]->properties)->toBe(['method' => 'password'])
            // A failed sign-in has no actor, only the address that was tried.
            ->and($byEvent['auth.login_failed'][0]->actor_id)->toBeNull()
            ->and($byEvent['auth.login_failed'][0]->properties)->toBe(['email' => 'nadia@example.test'])
            ->and($byEvent['member.role_changed'][0]->properties)->toEqual(['old' => ['Employee'], 'attributes' => ['Manager']])
            ->and($byEvent['member.role_changed'][0]->actor_label)->toBe($this->admin->email);

        // Every entry belongs to this workspace, and nothing secret was written.
        expect($trail->pluck('tenant_id')->unique()->all())->toBe([$this->acme->id])
            ->and($trail->toJson())->not->toContain('a-good-password', 'oops', $match[1]);
    });

    it('stamps an entry with tenant, actor, subject and where the request came from', function () {
        $membership = $this->membershipOf($this->acme, $this->admin);

        $entry = $this->inTenant($this->acme, fn (): AuditLog => ($this->logger)()->record(
            'test.happened',
            $membership,
            ['reason' => 'because'],
            $this->admin,
        ));

        expect($entry->id)->toBeString()->toHaveLength(26)
            ->and($entry->tenant_id)->toBe($this->acme->id)
            ->and($entry->actor_type)->toBe('user')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->actor_label)->toBe($this->admin->email)
            ->and($entry->subject_type)->toBe('membership')
            ->and($entry->subject_id)->toBe($membership->id)
            ->and($entry->subject_label)->toBe($this->admin->email)
            ->and($entry->properties)->toBe(['reason' => 'because'])
            ->and($entry->ip_address)->toBe('127.0.0.1')
            ->and($entry->refresh()->created_at)->not->toBeNull();
    });

    it('records the address and browser of the request that caused the entry', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', str_repeat('A', 600))
            ->post('http://acme.localhost/login', ['email' => $this->admin->email, 'password' => 'password']);

        $entry = $this->auditTrail($this->acme, 'auth.login')->sole();

        expect($entry->ip_address)->toBe('203.0.113.9')
            ->and($entry->user_agent)->toBe(str_repeat('A', 500));
    });

    it('keeps the actor\'s name on the entry after the account is gone', function () {
        $this->inTenant($this->acme, fn () => ($this->logger)()->record('test.happened', actor: $this->admin));

        $email = $this->admin->email;
        $this->admin->delete();

        expect($this->auditTrail($this->acme, 'test.happened')->sole()->actor_label)->toBe($email);
    });

    it('never writes the value of a sensitive key', function () {
        $entry = $this->inTenant($this->acme, fn (): AuditLog => ($this->logger)()->record('test.happened', properties: [
            'email' => 'visible@example.test',
            'password' => 'hunter2',
            'Password_Confirmation' => 'hunter2',
            'token' => 'tok_live_123',
            'API_KEY' => 'key_123',
            'old' => ['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'name' => 'Before'],
            'attributes' => ['remember_token' => 'abc', 'credentials' => ['user' => 'u', 'pass' => 'p'], 'name' => 'After'],
            'list' => [['access_token' => 'at_1', 'label' => 'kept']],
        ]));

        // (MySQL returns JSON object keys in its own order, hence toEqual.)
        expect($entry->refresh()->properties)->toEqual([
            'email' => 'visible@example.test',
            'password' => '[redacted]',
            'Password_Confirmation' => '[redacted]',
            'token' => '[redacted]',
            'API_KEY' => '[redacted]',
            'old' => ['two_factor_secret' => '[redacted]', 'name' => 'Before'],
            'attributes' => ['remember_token' => '[redacted]', 'credentials' => '[redacted]', 'name' => 'After'],
            'list' => [['access_token' => '[redacted]', 'label' => 'kept']],
        ]);

        expect($entry->toJson())->not->toContain('hunter2', 'tok_live_123', 'key_123', 'JBSWY3DPEHPK3PXP', 'at_1');
    });
});

describe('model changes recorded automatically', function () {
    it('records creation with the new values, minus keys and timestamps', function () {
        $user = $this->addMember($this->acme, Role::EMPLOYEE);
        $membership = $this->membershipOf($this->acme, $user);

        $entry = $this->auditTrail($this->acme, 'tenant_membership.created')->last();

        expect($entry->subject_id)->toBe($membership->id)
            ->and($entry->subject_label)->toBe($user->email)
            ->and(array_keys($entry->properties['attributes']))->toEqualCanonicalizing(['user_id', 'status', 'is_owner', 'joined_at', 'tenant_id'])
            ->and($entry->properties['attributes']['status'])->toBe('active');
    });

    it('records an update with the old and the new value of what changed, and only that', function () {
        $membership = $this->membershipOf($this->acme, $this->admin);

        $this->inTenant($this->acme, fn () => $membership->update(['status' => MembershipStatus::Suspended]));

        expect($this->auditTrail($this->acme, 'tenant_membership.updated')->sole()->properties)->toEqual([
            'old' => ['status' => 'active'],
            'attributes' => ['status' => 'suspended'],
        ]);

        // And back: the "old" value follows the record, it is not stuck on the first one.
        $this->inTenant($this->acme, fn () => $membership->update(['status' => MembershipStatus::Active, 'is_owner' => false]));

        expect($this->auditTrail($this->acme, 'tenant_membership.updated')->last()->properties)->toEqual([
            'old' => ['status' => 'suspended'],
            'attributes' => ['status' => 'active'],
        ]);
    });

    it('records nothing for a change that only touches excluded attributes', function () {
        $membership = $this->membershipOf($this->acme, $this->admin);

        $this->inTenant($this->acme, fn () => $membership->update(['last_seen_at' => now()]));

        expect($this->auditTrail($this->acme, 'tenant_membership.updated'))->toHaveCount(0);
    });

    it('leaves hidden attributes out of what it records', function () {
        $membership = $this->membershipOf($this->acme, $this->admin)->makeHidden('status');

        expect($membership->auditableAttributes(['id' => 'x', 'status' => 'active', 'user_id' => 'u', 'last_seen_at' => 'now', 'created_at' => 'now']))
            ->toBe(['user_id' => 'u']);
    });

    it('records a deletion', function () {
        $role = $this->createRole($this->acme, 'Temporary');

        $this->inTenant($this->acme, fn () => $role->delete());

        expect($this->auditTrail($this->acme, 'role.deleted')->sole()->subject_label)->toBe('Temporary');
    });

    it('records the system roles and the owner when a workspace is provisioned, inside that workspace', function () {
        $events = $this->auditTrail($this->globex)->pluck('event')->countBy()->all();

        expect($events)->toBe([
            'role.created' => 4,
            'role.permissions_changed' => 3,
            'tenant_membership.created' => 1,
        ]);
    });
});

describe('the trail is append-only', function () {
    beforeEach(function () {
        $this->entry = $this->inTenant($this->acme, fn (): AuditLog => ($this->logger)()->record('test.happened'));
    });

    it('cannot be changed', function () {
        $this->inTenant($this->acme, function (): void {
            expect(fn () => $this->entry->update(['event' => 'test.rewritten']))->toThrow(LogicException::class, 'immutable');
            expect(fn () => $this->entry->forceFill(['actor_label' => 'someone else'])->save())->toThrow(LogicException::class);
        });

        expect(AuditLog::withoutTenancy()->findOrFail($this->entry->id)->event)->toBe('test.happened');
    });

    it('cannot be deleted', function () {
        $this->inTenant($this->acme, function (): void {
            expect(fn () => $this->entry->delete())->toThrow(LogicException::class, 'cannot be deleted');
            expect(fn () => AuditLog::destroy($this->entry->id))->toThrow(LogicException::class);
        });

        expect(AuditLog::withoutTenancy()->whereKey($this->entry->id)->exists())->toBeTrue();
    });
});

describe('isolation', function () {
    beforeEach(function () {
        $this->inTenant($this->acme, fn () => ($this->logger)()->record('acme.secret_event', properties: ['note' => 'acme only']));
        $this->inTenant($this->globex, fn () => ($this->logger)()->record('globex.secret_event', properties: ['note' => 'globex only']));
        ($this->logger)()->record('platform.maintenance_started');
    });

    it('shows a workspace its own entries on the audit page, never another\'s or the platform\'s', function () {
        $acme = ($this->pageEvents)($this->acme);
        $globex = ($this->pageEvents)($this->globex);

        expect($acme)->toContain('acme.secret_event')
            ->not->toContain('globex.secret_event', 'platform.maintenance_started')
            ->and($globex)->toContain('globex.secret_event')
            ->not->toContain('acme.secret_event', 'platform.maintenance_started');
    });

    it('does not let filters reach across workspaces', function () {
        expect(($this->pageEvents)($this->acme, '?event=globex'))->toBe([])
            ->and(($this->pageEvents)($this->acme, '?event=platform'))->toBe([])
            ->and(($this->pageEvents)($this->acme, '?search='.urlencode($this->ownerOf($this->globex)->email)))->toBe([]);

        // The filter's own list of event families is scoped too.
        $this->startNewBrowserSession()
            ->actingAsOwner($this->acme)
            ->get('http://acme.localhost/audit-log')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('eventGroups', fn ($groups): bool => collect($groups)->all() === ['acme', 'role', 'tenant_membership']));
    });

    it('scopes the model the same way', function () {
        $this->inTenant($this->acme, function (): void {
            $events = AuditLog::query()->pluck('event');

            expect($events)->toContain('acme.secret_event')
                ->not->toContain('globex.secret_event', 'platform.maintenance_started')
                ->and(AuditLog::query()->where('event', 'globex.secret_event')->exists())->toBeFalse()
                ->and(AuditLog::query()->whereNull('tenant_id')->exists())->toBeFalse();
        });

        expect(fn () => AuditLog::query()->count())->toThrow(MissingTenantContextException::class);
    });

    it('keeps platform entries without a tenant, reachable only on purpose', function () {
        $platform = $this->auditTrail(null);

        expect($platform->pluck('event')->all())->toBe(['platform.maintenance_started'])
            ->and($platform->first()->tenant_id)->toBeNull()
            ->and(AuditLog::withoutTenancy()->whereNull('tenant_id')->count())->toBe(1);
    });

    it('files an entry under the tenant that is active, whoever the subject belongs to', function () {
        $theirMembership = $this->membershipOf($this->globex, $this->ownerOf($this->globex));

        // Recorded while acme is active: it is acme's entry. Nothing is written into globex's trail from here.
        $before = $this->auditTrail($this->globex)->count();

        $this->inTenant($this->acme, fn () => ($this->logger)()->record('test.happened', $theirMembership));

        expect($this->auditTrail($this->globex)->count())->toBe($before)
            ->and($this->auditTrail($this->acme, 'test.happened'))->toHaveCount(1);
    });
});

describe('the audit page', function () {
    it('lists entries newest first with what the reader needs', function () {
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
        $this->inTenant($this->acme, fn () => ($this->logger)()->record('test.first', actor: $this->admin));
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $this->inTenant($this->acme, fn () => ($this->logger)()->record('test.second', $this->membershipOf($this->acme, $this->admin), ['k' => 'v'], $this->admin));

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/audit-log?event=test')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Core/Pages/Audit/Index')
                    ->where('filters', ['search' => '', 'event' => 'test', 'from' => '', 'to' => '']);

                $entries = $page->toArray()['props']['entries']['data'];

                expect(array_column($entries, 'event'))->toBe(['test.second', 'test.first'])
                    ->and($entries[0])->toMatchArray([
                        'actor' => $this->admin->email,
                        'actorType' => 'user',
                        'subject' => $this->admin->email,
                        'subjectType' => 'membership',
                        'properties' => ['k' => 'v'],
                        'ipAddress' => '127.0.0.1',
                    ])
                    ->and(array_keys($entries[0]))->not->toContain('user_agent', 'tenant_id', 'actor_id');
            });
    });

    it('filters by person, event family and date', function () {
        foreach (['2026-09-10' => 'billing.paid', '2026-09-20' => 'member.invited', '2026-09-30' => 'member.joined'] as $date => $event) {
            $this->travelTo(Carbon::parse($date.' 12:00:00'));
            $this->inTenant($this->acme, fn () => ($this->logger)()->record($event, subjectLabel: 'someone@example.test'));
        }

        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

        $find = fn (string $query): array => array_values(array_filter(
            ($this->pageEvents)($this->acme, $query),
            fn (string $event): bool => in_array($event, ['billing.paid', 'member.invited', 'member.joined'], true),
        ));

        expect($find('?event=member'))->toBe(['member.joined', 'member.invited'])
            ->and($find('?event=member.joined'))->toBe(['member.joined'])
            ->and($find('?from=2026-09-15'))->toBe(['member.joined', 'member.invited'])
            ->and($find('?to=2026-09-20'))->toBe(['member.invited', 'billing.paid'])
            ->and($find('?from=2026-09-20&to=2026-09-20'))->toBe(['member.invited'])
            ->and($find('?search=someone%40example'))->toBe(['member.joined', 'member.invited', 'billing.paid'])
            ->and($find('?search=nobody'))->toBe([])
            ->and($find('?event=member&from=2026-09-25&search=someone'))->toBe(['member.joined']);
    });

    it('reads a date filter as a day on the workspace\'s clock, not on the server\'s', function () {
        // acme keeps Dhaka time (UTC+6): 19:00 UTC on the 9th is 01:00 on the 10th there.
        $this->travelTo(Carbon::parse('2026-09-09 19:00:00', 'UTC'));
        $this->inTenant($this->acme, fn () => ($this->logger)()->record('clock.early_on_the_tenth'));
        $this->travelTo(Carbon::parse('2026-09-10 17:59:00', 'UTC'));
        $this->inTenant($this->acme, fn () => ($this->logger)()->record('clock.late_on_the_tenth'));
        $this->travelTo(Carbon::parse('2026-09-10 18:30:00', 'UTC'));
        $this->inTenant($this->acme, fn () => ($this->logger)()->record('clock.already_the_eleventh'));
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'UTC'));

        expect($this->acme->timezone)->toBe('Asia/Dhaka')
            ->and(($this->pageEvents)($this->acme, '?event=clock&from=2026-09-10&to=2026-09-10'))
            ->toBe(['clock.late_on_the_tenth', 'clock.early_on_the_tenth'])
            ->and(($this->pageEvents)($this->acme, '?event=clock&to=2026-09-09'))->toBe([])
            ->and(($this->pageEvents)($this->acme, '?event=clock&from=2026-09-11'))->toBe(['clock.already_the_eleventh']);
    });

    it('matches an event family as a whole name, never as the start of another', function () {
        $this->inTenant($this->acme, function (): void {
            ($this->logger)()->record('member.joined');
            ($this->logger)()->record('membership_plan.changed');
        });

        expect(($this->pageEvents)($this->acme, '?event=member'))->toBe(['member.joined'])
            ->and(($this->pageEvents)($this->acme, '?event=membership_plan'))->toBe(['membership_plan.changed'])
            ->and(($this->pageEvents)($this->acme, '?event=mem'))->toBe([]);
    });

    it('searches for what was typed, wildcard characters included', function () {
        $this->inTenant($this->acme, function (): void {
            ($this->logger)()->record('search.plain', subjectLabel: 'Quarterly plan');
            ($this->logger)()->record('search.percent', subjectLabel: '50% discount');
            ($this->logger)()->record('search.underscore', subjectLabel: 'first_name');
        });

        $find = fn (string $term): array => ($this->pageEvents)($this->acme, '?event=search&search='.urlencode($term));

        expect($find('%'))->toBe(['search.percent'])
            ->and($find('_'))->toBe(['search.underscore'])
            ->and($find('first_name'))->toBe(['search.underscore'])
            ->and($find('firstXname'))->toBe([])
            ->and($find('plan'))->toBe(['search.plain']);
    });

    it('pages through a long trail with a cursor', function () {
        $this->inTenant($this->acme, function (): void {
            foreach (range(1, 35) as $n) {
                ($this->logger)()->record('bulk.entry', properties: ['n' => $n]);
            }
        });

        $next = null;

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/audit-log?event=bulk')
            ->assertInertia(function (AssertableInertia $page) use (&$next): void {
                $entries = $page->toArray()['props']['entries'];
                $next = $entries['next_page_url'];

                expect($entries['data'])->toHaveCount(30)
                    ->and($entries['per_page'])->toBe(30)
                    ->and($entries['data'][0]['properties'])->toBe(['n' => 35])
                    ->and($entries['prev_page_url'])->toBeNull()
                    // The link keeps the active filter.
                    ->and($next)->toStartWith('http://acme.localhost/audit-log?event=bulk&cursor=');
            });

        $previous = null;

        $this->get($next)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$previous): void {
                $entries = $page->toArray()['props']['entries'];
                $previous = $entries['prev_page_url'];

                expect($entries['data'])->toHaveCount(5)
                    ->and($entries['data'][0]['properties'])->toBe(['n' => 5])
                    ->and($entries['data'][4]['properties'])->toBe(['n' => 1])
                    ->and($entries['next_page_url'])->toBeNull()
                    ->and($previous)->toBeString();
            });

        // And back again to the same first page.
        $this->get($previous)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries.data', 30)
                ->where('entries.data.0.properties', ['n' => 35]));
    });

    it('validates its filters', function (string $query, string $field) {
        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/audit-log'.$query)
            ->assertSessionHasErrors($field);
    })->with([
        ['?from=not-a-date', 'from'],
        ['?from=2026-10-05&to=2026-10-01', 'to'],
        ['?search[]=a', 'search'],
        ['?event[]=a', 'event'],
        ['?search='.str_repeat('a', 101), 'search'],
    ]);
});

describe('the support families', function () {
    beforeEach(function () {
        $this->inTenant($this->acme, function (): void {
            ($this->logger)()->record('support.session_started');
            ($this->logger)()->record('support.ticket.created');
            ($this->logger)()->record('support.csat.answered');
        });
    });

    it('lists the support desk apart from support access', function () {
        $this->startNewBrowserSession()
            ->actingAsOwner($this->acme)
            ->get('http://acme.localhost/audit-log')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('eventGroups', fn ($groups): bool => in_array('support', collect($groups)->all(), true)
                    && in_array('support_desk', collect($groups)->all(), true)));
    });

    it('filters each family without the other', function () {
        expect(($this->pageEvents)($this->acme, '?event=support'))->toBe(['support.session_started'])
            ->and(collect(($this->pageEvents)($this->acme, '?event=support_desk'))->sort()->values()->all())
            ->toBe(['support.csat.answered', 'support.ticket.created']);
    });
});
