<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Models\Invitation;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Platform\Enums\PlatformRole;
use App\Platform\Impersonation\Impersonation;
use App\Platform\Impersonation\ImpersonationManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * Support access: a Super Admin opening a workspace as its owner.
 *
 * Four things always hold: it needs a written reason; it is time-limited;
 * the workspace can see it in its own audit trail; and it is fully audited
 * in the platform's trail as well.
 *
 * The session crosses from the admin host to the workspace's host with a
 * one-time token. The two hosts are different origins, hence different
 * browser sessions: the tests start a new one in between.
 */
beforeEach(function () {
    // A cache that is really shared between the admin host and the workspaces, as in
    // production: the suite's array store is thrown away on every context switch, and
    // opening a session switches context - which would hide the rate limit, for one.
    $this->useDatabaseCache();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->admin = $this->createPlatformUser();
    $this->support = $this->createPlatformUser(PlatformRole::SupportAdmin);

    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->globex = $this->createTenant('globex', ['name' => 'Globex']);
    $this->owner = $this->ownerOf($this->acme);

    /** Ask the panel for a support session. */
    $this->start = fn (string $reason = 'Ticket 4521: invoice page shows an error', ?Tenant $tenant = null, $as = null): TestResponse => $this
        ->actingAsPlatformUser($as ?? $this->admin)
        ->from('http://admin.localhost/tenants/'.($tenant ?? $this->acme)->id)
        ->post('http://admin.localhost/tenants/'.($tenant ?? $this->acme)->id.'/impersonate', ['reason' => $reason]);

    /** Open a session and return the URL that carries it to the workspace. */
    $this->handoff = function (string $reason = 'Ticket 4521: invoice page shows an error'): string {
        $response = ($this->start)($reason);
        $response->assertRedirect();

        return (string) $response->headers->get('Location');
    };

    /** Open a session and arrive in the workspace with it, in a browser session of its own. */
    $this->enter = function (string $reason = 'Ticket 4521: invoice page shows an error'): Impersonation {
        $url = ($this->handoff)($reason);

        $this->startNewBrowserSession();
        $this->get($url)->assertRedirect('http://acme.localhost');

        return Impersonation::query()->latest('id')->firstOrFail();
    };
});

describe('opening a session', function () {
    it('answers with a redirect to the workspace\'s own host that carries a one-time token', function () {
        $response = ($this->start)();
        $url = (string) $response->headers->get('Location');

        $response->assertRedirect()->assertSessionHasNoErrors();

        expect($url)->toMatch('#^http://acme\.localhost/auth/impersonate\?token=[A-Za-z0-9]{64}$#');

        $token = explode('token=', $url)[1];
        $session = Impersonation::query()->sole();

        expect($session->platform_user_id)->toBe($this->admin->id)
            ->and($session->tenant_id)->toBe($this->acme->id)
            ->and($session->user_id)->toBe($this->owner->id)
            ->and($session->reason)->toBe('Ticket 4521: invoice page shows an error')
            ->and($session->ip_address)->toBe('127.0.0.1')
            // Only a hash of the token is kept.
            ->and($session->token_hash)->toBe(hash('sha256', $token))
            ->and(DB::table('impersonations')->where('token_hash', $token)->exists())->toBeFalse()
            ->and($session->token_expires_at->toDateTimeString())->toBe('2026-03-10 09:01:00')
            ->and($session->expires_at->toDateTimeString())->toBe('2026-03-10 09:30:00')
            // Not started until the token is redeemed on the workspace's host.
            ->and($session->started_at)->toBeNull()
            ->and($session->ended_at)->toBeNull()
            ->and($session->isRunning())->toBeFalse();

        // Opening a session signs nobody in on the admin host's side of things.
        $this->assertGuest('web');
    });

    it('answers an Inertia visit with a full-page redirect to the other origin', function () {
        $this->actingAsPlatformUser($this->admin)
            ->withHeader('X-Inertia', 'true')
            ->post("http://admin.localhost/tenants/{$this->acme->id}/impersonate", ['reason' => 'Ticket 4521: invoice page shows an error'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');
    });

    it('needs a reason of at least ten characters', function (string $reason) {
        ($this->start)($reason)
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasErrors('reason');

        expect(Impersonation::query()->count())->toBe(0)
            ->and($this->auditTrail(null, 'platform.impersonation.started'))->toHaveCount(0)
            ->and($this->auditTrail($this->acme, 'support.session_started'))->toHaveCount(0);
    })->with([
        'none' => [''],
        'nine characters' => ['too short'],
        'only spaces' => ['              '],
        'too long' => [str_repeat('a', 501)],
    ]);

    it('is for a super admin only: a support admin cannot enter a workspace', function () {
        ($this->start)(as: $this->support)->assertForbidden();

        expect(Impersonation::query()->count())->toBe(0)
            ->and($this->auditTrail($this->acme, 'support.session_started'))->toHaveCount(0);
    });

    it('is not for guests, workspace owners or accounts without two-factor', function () {
        $url = "http://admin.localhost/tenants/{$this->acme->id}/impersonate";
        $input = ['reason' => 'Ticket 4521: invoice page shows an error'];

        $this->post($url, $input)->assertRedirect('http://admin.localhost/login');

        // Not even the owner of the workspace opens a support session into it.
        $this->actingAsOwner($this->acme)->post($url, $input)->assertRedirect('http://admin.localhost/login');

        $this->startNewBrowserSession()
            ->actingAsPlatformUser($this->createPlatformUser(twoFactor: false))
            ->post($url, $input)
            ->assertRedirect('http://admin.localhost/two-factor/setup');

        expect(Impersonation::query()->count())->toBe(0);
    });

    it('only opens a workspace that is active', function (TenantStatus $status) {
        $this->acme->update(['status' => $status]);

        ($this->start)()
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasErrors(['reason' => 'Only an active workspace can be opened.']);

        expect(Impersonation::query()->count())->toBe(0);
    })->with([TenantStatus::Pending, TenantStatus::Suspended]);

    it('needs an active owner to sign in as', function () {
        $this->membershipOf($this->acme, $this->owner)->update(['status' => MembershipStatus::Suspended]);

        ($this->start)()->assertSessionHasErrors(['reason' => 'This workspace has no active owner to sign in as.']);

        expect(Impersonation::query()->count())->toBe(0)
            ->and($this->auditTrail(null, 'platform.impersonation.started'))->toHaveCount(0);
    });

    it('answers 404 for a workspace that does not exist', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/tenants/01ARZ3NDEKTSV4RRFFQ69G5FAV/impersonate', ['reason' => 'Ticket 4521: invoice page shows an error'])
            ->assertNotFound();
    });

    it('is rate limited to ten a minute', function () {
        foreach (range(1, 10) as $ignored) {
            ($this->start)()->assertRedirect();
        }

        ($this->start)()->assertStatus(429);

        expect(Impersonation::query()->count())->toBe(10);

        $this->travel(61)->seconds();

        ($this->start)()->assertRedirect();
    });
});

describe('arriving in the workspace', function () {
    it('signs the operator in as the owner, without the owner\'s password or second factor', function () {
        // The owner has two-step verification; a member's sign-in would be challenged.
        $this->enableTwoFactor($this->owner);
        $passwordHash = $this->owner->refresh()->password;

        $url = ($this->handoff)();

        $this->startNewBrowserSession();
        $this->travel(20)->seconds();

        $this->get($url)->assertRedirect('http://acme.localhost');

        $this->assertAuthenticatedAs($this->owner, 'web');
        $this->assertGuest('platform');

        $session = Impersonation::query()->sole();

        expect(session(EnsureTenantMember::SESSION_KEY))->toBe($this->acme->id)
            ->and(session(ImpersonationManager::SESSION_KEY))->toBe([
                'id' => $session->id,
                'by' => $this->admin->email,
                'expires_at' => Carbon::parse('2026-03-10 09:30:20')->getTimestamp(),
            ])
            ->and($session->started_at->toDateTimeString())->toBe('2026-03-10 09:00:20')
            // The time limit is counted from the moment the session really starts.
            ->and($session->expires_at->toDateTimeString())->toBe('2026-03-10 09:30:20')
            // The token has done its job.
            ->and($session->token_hash)->toBeNull()
            ->and($session->token_expires_at)->toBeNull()
            ->and($session->isRunning())->toBeTrue()
            // Nothing about the owner's own account was touched.
            ->and($this->owner->refresh()->password)->toBe($passwordHash)
            ->and($this->owner->hasEnabledTwoFactor())->toBeTrue();
    });

    it('tells every page that this is a support session, and offers no other workspace', function () {
        // The owner also belongs to another workspace: a member would be offered the switch.
        $this->addMember($this->globex, Role::ADMIN, $this->owner);

        ($this->enter)();

        $this->get('http://acme.localhost/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.slug', 'acme')
                ->where('auth.user.email', $this->owner->email)
                ->where('auth.membership.isOwner', true)
                ->where('supportSession', ['by' => $this->admin->email, 'expiresAt' => '2026-03-10T09:30:00+00:00'])
                // A support session stays in the workspace it was opened for.
                ->where('workspaces', []));
    });

    it('accepts the token once only', function () {
        $url = ($this->handoff)();

        $this->startNewBrowserSession();
        $this->get($url)->assertRedirect('http://acme.localhost');

        $this->startNewBrowserSession();
        $this->get($url)->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
        expect(session(ImpersonationManager::SESSION_KEY))->toBeNull();
    });

    it('expires the token after sixty seconds', function () {
        $url = ($this->handoff)();

        $this->startNewBrowserSession();
        $this->travel(config('platform.impersonation.token_ttl') + 1)->seconds();

        $this->get($url)->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
        expect(Impersonation::query()->sole()->started_at)->toBeNull();
    });

    it('accepts the token just before it expires', function () {
        $url = ($this->handoff)();

        $this->startNewBrowserSession();
        $this->travel(59)->seconds();

        $this->get($url)->assertRedirect('http://acme.localhost');
        $this->assertAuthenticatedAs($this->owner, 'web');
    });

    it('does not accept the token on another workspace\'s host', function () {
        // The owner of acme is a member of globex too: the token still belongs to acme alone.
        $this->addMember($this->globex, Role::ADMIN, $this->owner);

        $token = explode('token=', ($this->handoff)())[1];

        $this->startNewBrowserSession();

        $this->get("http://globex.localhost/auth/impersonate?token={$token}")->assertRedirect('http://globex.localhost/login');

        $this->assertGuest('web');
        expect(session(ImpersonationManager::SESSION_KEY))->toBeNull()
            ->and(Impersonation::query()->sole()->started_at)->toBeNull();

        // Nor on the central site or the admin host, where the route does not exist.
        $this->get("http://localhost/auth/impersonate?token={$token}")->assertNotFound();
        $this->get("http://admin.localhost/auth/impersonate?token={$token}")->assertNotFound();

        $this->assertGuest('web');
    });

    it('ignores a missing, malformed or made-up token', function (string $query) {
        ($this->handoff)();
        $this->startNewBrowserSession();

        $this->get('http://acme.localhost/auth/impersonate'.$query)->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
    })->with([
        'missing' => [''],
        'empty' => ['?token='],
        'too short' => ['?token=abc'],
        'wrong characters' => ['?token='.str_repeat('-', 64)],
        'well formed but unknown' => ['?token='.str_repeat('a', 64)],
        'an array' => ['?token[]=a'],
    ]);

    it('refuses a session that became impossible between opening and arriving', function (string $case) {
        $url = ($this->handoff)();

        match ($case) {
            'the owner was suspended' => $this->membershipOf($this->acme, $this->owner)->update(['status' => MembershipStatus::Suspended]),
            'the workspace was suspended' => $this->acme->update(['status' => TenantStatus::Suspended]),
        };

        $this->startNewBrowserSession();
        $response = $this->get($url);

        // A suspended workspace answers "unavailable" to everything; otherwise it is the sign-in page.
        $case === 'the workspace was suspended'
            ? $response->assertForbidden()
            : $response->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
        expect(Impersonation::query()->sole()->started_at)->toBeNull();
    })->with(['the owner was suspended', 'the workspace was suspended']);
});

describe('during a session', function () {
    beforeEach(function () {
        $this->session = ($this->enter)();
    });

    it('refuses what would act on the owner as a person', function (string $method, string $path, array $input) {
        $before = $this->owner->refresh()->getAttributes();

        $this->call($method, 'http://acme.localhost'.$path, $input)->assertForbidden();

        // Refused, not merely failed: the session is still there, and so is the owner's account as it was.
        expect($this->owner->refresh()->getAttributes())->toEqual($before)
            ->and(session(ImpersonationManager::SESSION_KEY))->not->toBeNull();

        $this->assertAuthenticatedAs($this->owner, 'web');
    })->with([
        'their profile' => ['PUT', '/settings/profile', ['name' => 'Renamed By Support', 'locale' => 'bn', 'numerals' => 'beng']],
        'their password' => ['PUT', '/settings/password', ['current_password' => 'password', 'password' => 'new-password-1234', 'password_confirmation' => 'new-password-1234']],
        'turning two-factor on' => ['POST', '/settings/two-factor', []],
        'confirming two-factor' => ['POST', '/settings/two-factor/confirm', ['code' => '123456']],
        'new recovery codes' => ['POST', '/settings/two-factor/recovery-codes', ['password' => 'password']],
        'turning two-factor off' => ['DELETE', '/settings/two-factor', ['password' => 'password']],
        'their language' => ['POST', '/locale', ['locale' => 'bn']],
        'hopping to another workspace' => ['POST', '/workspaces/globex/switch', []],
        // The pages too: the security page shows the secret of a two-step enrolment that is under way.
        'opening their profile' => ['GET', '/settings/profile', []],
        'opening their security page' => ['GET', '/settings/security', []],
    ]);

    it('never shows the secret of a two-step enrolment the owner has started', function () {
        // The owner began to enrol and has not confirmed yet: their own security page shows the secret.
        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->owner)
            ->post('http://acme.localhost/settings/two-factor', ['current_password' => 'password']);

        $secret = $this->owner->refresh()->twoFactorSecret();

        expect($secret)->not->toBeNull();

        ($this->enter)();

        $this->get('http://acme.localhost/settings/security')
            ->assertForbidden()
            ->assertDontSee($secret);
    });

    it('does not let the session hop to another workspace the owner belongs to', function () {
        $this->addMember($this->globex, Role::ADMIN, $this->owner);

        $this->post('http://acme.localhost/workspaces/globex/switch')->assertForbidden();

        // And the workspace session of acme is no session of globex.
        $this->get('http://globex.localhost/')->assertRedirect('http://globex.localhost/login');
    });

    it('allows working in the workspace, as its owner', function () {
        $this->get('http://acme.localhost/')->assertOk();
        $this->get('http://acme.localhost/team/members')->assertOk();
        $this->get('http://acme.localhost/team/roles')->assertOk();
        $this->get('http://acme.localhost/audit-log')->assertOk();

        $this->post('http://acme.localhost/organization/branches', ['name' => 'Dhaka'])
            ->assertRedirect()->assertSessionHasNoErrors();
    });

    it('refuses to create durable access: invitations, members, roles and apps', function (string $method, string $path, array $input) {
        $before = $this->auditTrail($this->acme)->count();

        // Route-model binding runs before the support-session guard, so the ids must exist.
        $path = str_replace(
            ['{member}', '{role}'],
            [$this->membershipOf($this->acme, $this->owner)->id, $this->roleOf($this->acme, Role::EMPLOYEE)->id],
            $path,
        );

        $this->call($method, 'http://acme.localhost'.$path, $input)->assertForbidden();

        $written = $this->auditTrail($this->acme)->slice($before)->values();

        expect($written->pluck('event')->all())->toContain('support.action_blocked')
            ->and(session(ImpersonationManager::SESSION_KEY))->not->toBeNull();
    })->with(fn () => [
        'inviting someone' => ['POST', '/team/invitations', ['email' => 'new.hire@example.test', 'role_id' => str_repeat('0', 26)]],
        'changing a member' => ['PATCH', '/team/members/{member}', []],
        'removing a member' => ['DELETE', '/team/members/{member}', []],
        'creating a role' => ['POST', '/team/roles', ['name' => 'Backdoor', 'permissions' => ['core.audit.view']]],
        'changing a role' => ['PUT', '/team/roles/{role}', []],
        'installing an app' => ['POST', '/apps/crm/install', []],
        'activating an app' => ['POST', '/apps/crm/activate', []],
    ]);

    it('creates no invitation or role while the session lasts', function () {
        $this->post('http://acme.localhost/team/invitations', [
            'email' => 'new.hire@example.test',
            'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id,
        ])->assertForbidden();

        expect(Invitation::forTenant($this->acme)->where('email', 'new.hire@example.test')->exists())->toBeFalse();
    });

    it('marks every audit entry written during it as done through a support session', function () {
        $marker = ['id' => $this->session->id, 'by' => $this->admin->email];
        $before = $this->auditTrail($this->acme)->count();

        $this->post('http://acme.localhost/organization/branches', ['name' => 'Dhaka'])->assertSessionHasNoErrors();
        $this->post('http://acme.localhost/organization/departments', ['name' => 'Sales'])->assertSessionHasNoErrors();

        $written = $this->auditTrail($this->acme)->slice($before)->values();

        expect($written->count())->toBeGreaterThanOrEqual(2);

        foreach ($written as $entry) {
            // Whoever the entry names as its actor, it says who was really at the keyboard.
            expect($entry->properties['support_session'] ?? null)->toEqual($marker, "[{$entry->event}] is not marked as a support session.");
        }

        // The entry names the owner as its actor: that is who the session is signed in as.
        expect($written->first()->actor_id)->toBe($this->owner->id);
    });

    it('ends by itself after thirty minutes: the next request signs out and records it', function () {
        // Still inside the limit.
        $this->travel(29)->minutes();
        $this->get('http://acme.localhost/')->assertOk();

        $this->travel(1)->minutes();

        $this->get('http://acme.localhost/team/members')->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');

        expect(session(ImpersonationManager::SESSION_KEY))->toBeNull()
            ->and($this->session->refresh()->ended_at->toDateTimeString())->toBe('2026-03-10 09:30:00')
            ->and($this->session->isRunning())->toBeFalse();

        $platform = $this->auditTrail(null, 'platform.impersonation.ended')->sole();
        $workspace = $this->auditTrail($this->acme, 'support.session_ended')->sole();

        foreach ([$platform, $workspace] as $entry) {
            expect($entry->actor_type)->toBe('platform_user')
                ->and($entry->actor_id)->toBe($this->admin->id)
                ->and($entry->properties['session'])->toBe($this->session->id)
                ->and($entry->properties['expired'])->toBeTrue();
        }

        // Signed out for good: nothing of the session is left to use.
        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');
    });

    it('follows the configured time limit', function () {
        config(['platform.impersonation.ttl_minutes' => 5]);

        $this->startNewBrowserSession();
        $session = ($this->enter)('A second look at the same ticket');

        expect($session->expires_at->toDateTimeString())->toBe('2026-03-10 09:05:00');

        $this->travel(5)->minutes();
        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');

        expect($session->refresh()->ended_at)->not->toBeNull();
    });

    it('is ended by the operator: signs out, records it and goes back to the panel', function () {
        $this->travel(4)->minutes();

        $this->post('http://acme.localhost/auth/impersonate/stop')
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}");

        $this->assertGuest('web');

        expect(session(ImpersonationManager::SESSION_KEY))->toBeNull()
            ->and($this->session->refresh()->ended_at->toDateTimeString())->toBe('2026-03-10 09:04:00');

        expect($this->auditTrail(null, 'platform.impersonation.ended')->sole()->properties['expired'])->toBeFalse()
            ->and($this->auditTrail($this->acme, 'support.session_ended')->sole()->properties['expired'])->toBeFalse();

        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');
    });

    it('is ended and recorded by an ordinary sign-out as well', function () {
        $this->travel(6)->minutes();

        $this->post('http://acme.localhost/logout')->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');

        expect(session(ImpersonationManager::SESSION_KEY))->toBeNull()
            ->and($this->session->refresh()->ended_at->toDateTimeString())->toBe('2026-03-10 09:06:00')
            ->and($this->auditTrail(null, 'platform.impersonation.ended'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'support.session_ended')->sole()->properties['expired'])->toBeFalse()
            // The owner did not sign out; the operator left.
            ->and($this->auditTrail($this->acme, 'auth.logout'))->toHaveCount(0);

        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');
    });

    it('leaves the panel with a full-page redirect when the stop comes from an Inertia visit', function () {
        $this->withHeader('X-Inertia', 'true')
            ->post('http://acme.localhost/auth/impersonate/stop')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', "http://admin.localhost/tenants/{$this->acme->id}");

        $this->assertGuest('web');
    });
});

describe('the record of a session', function () {
    it('is in both trails, start and end, under the operator\'s name', function () {
        $session = ($this->enter)('Ticket 4521: invoice page shows an error');

        $this->travel(3)->minutes();
        $this->post('http://acme.localhost/auth/impersonate/stop');

        $started = [
            $this->auditTrail(null, 'platform.impersonation.started')->sole(),
            $this->auditTrail($this->acme, 'support.session_started')->sole(),
        ];

        $ended = [
            $this->auditTrail(null, 'platform.impersonation.ended')->sole(),
            $this->auditTrail($this->acme, 'support.session_ended')->sole(),
        ];

        foreach ([...$started, ...$ended] as $entry) {
            // The operator, not the owner the session was signed in as.
            expect($entry->actor_type)->toBe('platform_user')
                ->and($entry->actor_id)->toBe($this->admin->id)
                ->and($entry->actor_label)->toBe($this->admin->email)
                ->and($entry->subject_type)->toBe('tenant')
                ->and($entry->subject_id)->toBe($this->acme->id)
                ->and($entry->properties['session'])->toBe($session->id);
        }

        foreach ($started as $entry) {
            // The workspace can read who came in, as whom, why, and for how long.
            expect($entry->properties)->toEqual([
                'session' => $session->id,
                'as' => $this->owner->email,
                'reason' => 'Ticket 4521: invoice page shows an error',
                'minutes' => 30,
            ]);
        }

        // One fact, two trails.
        expect($started[0]->tenant_id)->toBeNull()
            ->and($started[1]->tenant_id)->toBe($this->acme->id)
            ->and($ended[0]->tenant_id)->toBeNull()
            ->and($ended[1]->tenant_id)->toBe($this->acme->id);

        // Nothing of it leaks into another workspace's trail.
        expect($this->auditTrail($this->globex)->pluck('event')->filter(fn (string $event): bool => str_starts_with($event, 'support.'))->all())->toBe([]);
    });

    it('is what the workspace\'s owner sees in their own audit log', function () {
        ($this->enter)();
        $this->post('http://acme.localhost/auth/impersonate/stop');

        $this->startNewBrowserSession()
            ->actingAsOwner($this->acme)
            ->get('http://acme.localhost/audit-log')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $entries = collect($page->toArray()['props']['entries']['data'])->keyBy('event');

                expect($entries->keys()->all())->toContain('support.session_started', 'support.session_ended')
                    ->and($entries['support.session_started']['actor'])->toBe($this->admin->email)
                    ->and($entries['support.session_started']['properties']['reason'])->toBe('Ticket 4521: invoice page shows an error');
            });
    });

    it('is listed on the workspace\'s page in the panel', function () {
        ($this->enter)();

        $this->travel(2)->minutes();
        $this->post('http://acme.localhost/auth/impersonate/stop');

        // A second one that is opened and never used.
        $this->startNewBrowserSession();
        ($this->handoff)('Looked again, nothing to do after all');

        $this->get("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('supportSessions', 2)
                // Newest first.
                ->where('supportSessions.0.by', $this->admin->email)
                ->where('supportSessions.0.reason', 'Looked again, nothing to do after all')
                ->where('supportSessions.0.startedAt', null)
                ->where('supportSessions.0.endedAt', null)
                ->where('supportSessions.1.reason', 'Ticket 4521: invoice page shows an error')
                ->where('supportSessions.1.startedAt', '2026-03-10T09:00:00+00:00')
                ->where('supportSessions.1.endedAt', '2026-03-10T09:02:00+00:00')
                ->where('supportSessions.1.expiresAt', '2026-03-10T09:30:00+00:00')
                // The token, hashed or not, is nobody's business.
                ->missing('supportSessions.0.token_hash')
                ->missing('supportSessions.0.tokenHash'));

        // Another workspace's page does not list it.
        $this->get("http://admin.localhost/tenants/{$this->globex->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('supportSessions', []));
    });
});

describe('an ordinary member\'s session', function () {
    it('is not a support session and is left alone by all of this', function () {
        $this->addMember($this->globex, Role::EMPLOYEE, $this->owner);

        $this->actingAsOwner($this->acme);

        $this->get('http://acme.localhost/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('supportSession', null)
                ->has('workspaces', 2));

        // What a support session may not do, the person themselves may.
        $this->put('http://acme.localhost/settings/profile', ['name' => 'Renamed By Themselves', 'locale' => 'bn', 'numerals' => 'beng'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->put('http://acme.localhost/settings/password', [
            'current_password' => 'password',
            'password' => 'a-new-password-1234',
            'password_confirmation' => 'a-new-password-1234',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post('http://acme.localhost/locale', ['locale' => 'en'])->assertRedirect();
        $this->post('http://acme.localhost/workspaces/globex/switch')->assertRedirect();

        $this->owner->refresh();

        expect($this->owner->name)->toBe('Renamed By Themselves')
            ->and($this->owner->locale)->toBe('en')
            ->and(Hash::check('a-new-password-1234', $this->owner->password))->toBeTrue();

        // No time limit either: an hour later the session is still theirs.
        $this->travel(60)->minutes();
        $this->get('http://acme.localhost/')->assertOk();
        $this->assertAuthenticatedAs($this->owner, 'web');

        // And their audit entries carry no support marker.
        $marked = $this->auditTrail($this->acme)->filter(fn ($entry): bool => isset($entry->properties['support_session']));

        expect($marked)->toHaveCount(0)
            ->and(Impersonation::query()->count())->toBe(0);
    });

    it('cannot be "stopped" like a support session', function () {
        $this->actingAsOwner($this->acme)
            ->from('http://acme.localhost/team/members')
            ->post('http://acme.localhost/auth/impersonate/stop')
            ->assertRedirect('http://acme.localhost/team/members')
            ->assertInertiaFlash('toast.type', 'info');

        // Still signed in, and nothing was recorded.
        $this->assertAuthenticatedAs($this->owner, 'web');

        expect($this->auditTrail($this->acme, 'support.session_ended'))->toHaveCount(0)
            ->and($this->auditTrail(null, 'platform.impersonation.ended'))->toHaveCount(0);
    });

    it('needs a signed-in member to stop anything', function () {
        $this->post('http://acme.localhost/auth/impersonate/stop')->assertRedirect('http://acme.localhost/login');
    });
});
