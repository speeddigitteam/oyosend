<?php

declare(strict_types=1);

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->signIn = fn (string $email, string $password = 'password'): TestResponse => $this
        ->from('http://admin.localhost/login')
        ->post('http://admin.localhost/login', ['email' => $email, 'password' => $password]);

    $this->challenge = fn (array $input): TestResponse => $this
        ->from('http://admin.localhost/two-factor-challenge')
        ->post('http://admin.localhost/two-factor-challenge', $input);
});

describe('signing in', function () {
    it('shows the admin sign-in page', function () {
        $this->get('http://admin.localhost/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Auth/Login')
                ->where('app.area', 'platform')
                ->where('auth.user', null));
    });

    it('sends a guest to the admin sign-in page', function () {
        $this->get('http://admin.localhost/')->assertRedirect('http://admin.localhost/login');
        $this->get('http://admin.localhost/tenants')->assertRedirect('http://admin.localhost/login');
        $this->get('http://admin.localhost/two-factor/setup')->assertRedirect('http://admin.localhost/login');
    });

    it('signs a platform user in with the platform guard, in the central context', function () {
        $admin = $this->createPlatformUser(twoFactor: false);

        ($this->signIn)($admin->email)->assertRedirect('http://admin.localhost');

        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertGuest('web');

        expect(app(TenantContext::class)->check())->toBeFalse()
            ->and($admin->refresh()->last_login_at)->not->toBeNull();

        // A platform-level audit entry: it belongs to no workspace.
        $entry = $this->auditTrail(null, 'platform.auth.login')->sole();

        expect($entry->tenant_id)->toBeNull()
            ->and($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($admin->id)
            ->and($entry->properties)->toBe(['method' => 'password']);
    });

    it('fails identically for a wrong password and an unknown address', function () {
        $admin = $this->createPlatformUser();

        $wrong = ($this->signIn)($admin->email, 'not-the-password');
        $wrong->assertRedirect('http://admin.localhost/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $unknown = ($this->signIn)('nobody@example.test');
        $unknown->assertRedirect('http://admin.localhost/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest('platform');

        $failures = $this->auditTrail(null, 'platform.auth.login_failed');

        expect($failures)->toHaveCount(2)
            ->and($failures->pluck('tenant_id')->filter()->all())->toBe([]);
    });

    it('does not accept a workspace account, whatever its role', function () {
        $tenant = $this->createTenant('acme');
        $owner = $this->ownerOf($tenant);

        ($this->signIn)($owner->email)
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest('platform');
        $this->assertGuest('web');
    });

    it('does not accept a workspace account that shares its address and password with nobody on the platform', function () {
        // Same address in both tables: the two are still different people.
        $admin = $this->createPlatformUser();
        $twin = User::factory()->create(['email' => $admin->email, 'password' => 'workspace-only-password']);

        ($this->signIn)($twin->email, 'workspace-only-password')->assertSessionHasErrors('email');

        $this->assertGuest('platform');
    });

    it('does not let a workspace session into the panel', function () {
        $tenant = $this->createTenant('acme');

        $this->actingAsOwner($tenant)->get('http://admin.localhost/')->assertRedirect('http://admin.localhost/login');
        $this->actingAsOwner($tenant)->get('http://admin.localhost/tenants')->assertRedirect('http://admin.localhost/login');
    });

    it('locks an address out after five failures', function () {
        $admin = $this->createPlatformUser(twoFactor: false);

        foreach (range(1, 5) as $attempt) {
            ($this->signIn)($admin->email, 'wrong');
        }

        ($this->signIn)($admin->email)->assertSessionHasErrors('email');

        expect(session('errors')->first('email'))->toStartWith('Too many login attempts.');
        $this->assertGuest('platform');

        $this->travel(61)->seconds();

        ($this->signIn)($admin->email)->assertRedirect('http://admin.localhost');
        $this->assertAuthenticatedAs($admin, 'platform');
    });

    it('signs out and records it', function () {
        $admin = $this->createPlatformUser();

        $this->actingAsPlatformUser($admin)->post('http://admin.localhost/logout')->assertRedirect('http://admin.localhost/login');

        $this->assertGuest('platform');

        expect($this->auditTrail(null, 'platform.auth.logout')->sole()->actor_id)->toBe($admin->id);

        $this->get('http://admin.localhost/')->assertRedirect('http://admin.localhost/login');
    });

    it('sends a signed-in platform user away from the sign-in page', function () {
        $this->actingAsPlatformUser($this->createPlatformUser())
            ->get('http://admin.localhost/login')
            ->assertRedirect('http://admin.localhost');
    });
});

describe('mandatory two-factor enrolment', function () {
    beforeEach(function () {
        $this->admin = $this->createPlatformUser(twoFactor: false);
    });

    it('confines an account without two-factor to the enrolment screen', function (string $path) {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost'.$path)
            ->assertRedirect('http://admin.localhost/two-factor/setup');
    })->with(['/', '/tenants', '/horizon', '/horizon/api/stats']);

    it('still lets that account sign out', function () {
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/logout')->assertRedirect('http://admin.localhost/login');

        $this->assertGuest('platform');
    });

    it('shows the enrolment screen with a fresh secret', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/two-factor/setup')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Auth/TwoFactorSetup')
                ->where('secret', $this->admin->refresh()->two_factor_secret)
                ->where('qrCode', fn (string $svg): bool => str_starts_with($svg, '<svg')));

        $secret = $this->admin->two_factor_secret;
        expect($secret)->toBeString()->toHaveLength(32);

        // Reloading the screen does not rotate the secret under the authenticator app.
        $this->get('http://admin.localhost/two-factor/setup')->assertOk();
        expect($this->admin->refresh()->two_factor_secret)->toBe($secret);
    });

    it('enrols with a valid code, shows the recovery codes once and opens the panel', function () {
        $this->actingAsPlatformUser($this->admin)->get('http://admin.localhost/two-factor/setup');
        $this->admin->refresh();

        $this->post('http://admin.localhost/two-factor/setup', ['code' => $this->wrongTwoFactorCode($this->admin)])
            ->assertSessionHasErrors('code');

        expect($this->admin->refresh()->hasEnabledTwoFactor())->toBeFalse();
        $this->get('http://admin.localhost/')->assertRedirect('http://admin.localhost/two-factor/setup');

        $this->post('http://admin.localhost/two-factor/setup', ['code' => $this->twoFactorCode($this->admin)])
            ->assertRedirect('http://admin.localhost')
            ->assertInertiaFlash('recoveryCodes', $this->admin->refresh()->recoveryCodes());

        expect($this->admin->hasEnabledTwoFactor())->toBeTrue()
            ->and($this->admin->recoveryCodes())->toHaveCount(8)
            ->and($this->auditTrail(null, 'platform.auth.two_factor_enabled')->sole()->actor_id)->toBe($this->admin->id);

        $this->get('http://admin.localhost/')->assertOk();

        // Enrolment is done: the screen is closed.
        $this->get('http://admin.localhost/two-factor/setup')->assertRedirect('http://admin.localhost');
        $this->post('http://admin.localhost/two-factor/setup', ['code' => '123456'])->assertRedirect('http://admin.localhost');
    });

    it('can be relaxed outside production by configuration', function () {
        config(['platform.two_factor.required_for_platform' => false]);

        $this->actingAsPlatformUser($this->admin)->get('http://admin.localhost/')->assertOk();
    });
});

describe('the two-factor challenge', function () {
    beforeEach(function () {
        $this->admin = PlatformUser::factory()->create();
        $this->recoveryCodes = $this->enableTwoFactor($this->admin);
    });

    it('asks an enrolled account for its code at the next sign-in', function () {
        ($this->signIn)($this->admin->email)->assertRedirect('http://admin.localhost/two-factor-challenge');

        $this->assertGuest('platform');
        expect($this->auditTrail(null, 'platform.auth.login'))->toHaveCount(0);

        $this->get('http://admin.localhost/two-factor-challenge')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Platform/Pages/Auth/TwoFactorChallenge'));

        $this->get('http://admin.localhost/')->assertRedirect('http://admin.localhost/login');
    });

    it('signs in with a valid code', function () {
        ($this->signIn)($this->admin->email);

        ($this->challenge)(['code' => $this->twoFactorCode($this->admin)])->assertRedirect('http://admin.localhost');

        $this->assertAuthenticatedAs($this->admin, 'platform');
        expect($this->auditTrail(null, 'platform.auth.login')->sole()->properties)->toBe(['method' => 'two_factor']);

        $this->get('http://admin.localhost/')->assertOk();
    });

    it('rejects a wrong code, and a replayed one', function () {
        $code = $this->twoFactorCode($this->admin);

        ($this->signIn)($this->admin->email);
        ($this->challenge)(['code' => $this->wrongTwoFactorCode($this->admin)])->assertSessionHasErrors('code');
        $this->assertGuest('platform');

        ($this->challenge)(['code' => $code])->assertRedirect('http://admin.localhost');
        $this->post('http://admin.localhost/logout');

        ($this->signIn)($this->admin->email);
        ($this->challenge)(['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('platform');
    });

    it('signs in with a recovery code and burns it', function () {
        ($this->signIn)($this->admin->email);

        ($this->challenge)(['recovery_code' => $this->recoveryCodes[0]])->assertRedirect('http://admin.localhost');

        $this->assertAuthenticatedAs($this->admin, 'platform');

        expect($this->admin->refresh()->recoveryCodes())->toHaveCount(7)->not->toContain($this->recoveryCodes[0]);

        $this->post('http://admin.localhost/logout');
        ($this->signIn)($this->admin->email);
        ($this->challenge)(['recovery_code' => $this->recoveryCodes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest('platform');
    });

    it('discards the pending sign-in after five wrong codes', function () {
        ($this->signIn)($this->admin->email);

        foreach (range(1, 4) as $attempt) {
            ($this->challenge)(['code' => $this->wrongTwoFactorCode($this->admin)])->assertSessionHasErrors('code');
        }

        ($this->challenge)(['code' => $this->wrongTwoFactorCode($this->admin)])
            ->assertRedirect('http://admin.localhost/login')
            ->assertSessionHasErrors('email');

        ($this->challenge)(['code' => $this->twoFactorCode($this->admin)])->assertRedirect('http://admin.localhost/login');
        $this->assertGuest('platform');
    });

    it('keeps the two guards\' pending sign-ins apart', function () {
        // Password accepted at the panel; the code is then offered to a workspace.
        $tenant = $this->createTenant('acme');

        ($this->signIn)($this->admin->email);

        $this->post('http://acme.localhost/two-factor-challenge', ['code' => $this->twoFactorCode($this->admin)])
            ->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
        $this->assertGuest('platform');
    });
});

describe('abilities', function () {
    it('gives a super admin every platform ability and a support admin read access only', function () {
        $super = $this->createPlatformUser(PlatformRole::SuperAdmin);
        $support = $this->createPlatformUser(PlatformRole::SupportAdmin);

        foreach (['platform.dashboard.view', 'platform.tenants.view'] as $ability) {
            expect(Gate::forUser($super)->allows($ability))->toBeTrue()
                ->and(Gate::forUser($support)->allows($ability))->toBeTrue();
        }

        foreach (['platform.tenants.manage', 'platform.tenants.impersonate', 'platform.plans.manage', 'platform.anything.else'] as $ability) {
            expect(Gate::forUser($super)->allows($ability))->toBeTrue()
                ->and(Gate::forUser($support)->allows($ability))->toBeFalse("support admin was granted [{$ability}]");
        }
    });

    it('lets both roles open the pages they are allowed to read', function (PlatformRole $role) {
        $user = $this->createPlatformUser($role);

        $this->actingAsPlatformUser($user)->get('http://admin.localhost/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.role', $role->value));

        $this->get('http://admin.localhost/tenants')->assertOk();
    })->with([PlatformRole::SuperAdmin, PlatformRole::SupportAdmin]);

    it('never gives a platform user a workspace permission', function () {
        $tenant = $this->createTenant('acme');
        $super = $this->createPlatformUser();

        $this->inTenant($tenant, function () use ($super): void {
            foreach (['core.members.view', 'core.members.manage', 'core.roles.manage', 'core.audit.view'] as $permission) {
                expect(Gate::forUser($super)->allows($permission))->toBeFalse();
            }
        });
    });

    it('never gives a workspace user a platform ability', function () {
        $tenant = $this->createTenant('acme');
        $owner = $this->ownerOf($tenant);

        foreach (['platform.dashboard.view', 'platform.tenants.view', 'platform.tenants.manage', 'viewHorizon'] as $ability) {
            expect(Gate::forUser($owner)->allows($ability))->toBeFalse()
                ->and($this->inTenant($tenant, fn (): bool => Gate::forUser($owner)->allows($ability)))->toBeFalse();
        }
    });
});

describe('Horizon', function () {
    it('is for super admins only', function () {
        expect(Gate::forUser($this->createPlatformUser(PlatformRole::SuperAdmin))->allows('viewHorizon'))->toBeTrue()
            ->and(Gate::forUser($this->createPlatformUser(PlatformRole::SupportAdmin))->allows('viewHorizon'))->toBeFalse()
            ->and(Gate::allows('viewHorizon'))->toBeFalse();
    });

    it('opens for a super admin on the admin host', function () {
        $this->actingAsPlatformUser($this->createPlatformUser(PlatformRole::SuperAdmin))
            ->get('http://admin.localhost/horizon')
            ->assertOk();
    });

    it('is forbidden to a support admin', function () {
        $this->actingAsPlatformUser($this->createPlatformUser(PlatformRole::SupportAdmin))
            ->get('http://admin.localhost/horizon')
            ->assertForbidden();
    });

    it('is not there for guests, workspace users or other hosts', function () {
        $tenant = $this->createTenant('acme');

        $this->get('http://admin.localhost/horizon')->assertRedirect('http://admin.localhost/login');
        $this->actingAsOwner($tenant)->get('http://admin.localhost/horizon')->assertRedirect('http://admin.localhost/login');

        $this->startNewBrowserSession();
        $this->actingAsPlatformUser($this->createPlatformUser())->get('http://localhost/horizon')->assertNotFound();
        $this->get('http://acme.localhost/horizon')->assertNotFound();

        expect(Auth::guard('platform')->check())->toBeTrue();
    });
});
