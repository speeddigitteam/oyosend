<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->globex = $this->createTenant('globex');
    $this->member = $this->addMember($this->acme, Role::EMPLOYEE);

    $this->signIn = fn (Tenant $tenant, string $email, string $password = 'password', array $extra = []): TestResponse => $this->post(
        $this->tenantUrl($tenant, 'login'),
        ['email' => $email, 'password' => $password, ...$extra],
    );
});

it('shows the sign-in page of the workspace', function () {
    $this->get('http://acme.localhost/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Auth/Login')
            ->where('workspace', 'Acme Trading')
            ->where('auth.user', null)
            ->where('auth.permissions', [])
            ->where('navigation', [])
            ->where('workspaces', []));
});

it('sends a guest to the sign-in page and back to where they were going', function () {
    $this->get('http://acme.localhost/team/members')->assertRedirect('http://acme.localhost/login');

    ($this->signIn)($this->acme, $this->member->email)->assertRedirect('http://acme.localhost/team/members');
});

it('signs a member in and binds the session to the workspace', function () {
    ($this->signIn)($this->acme, $this->member->email)->assertRedirect('http://acme.localhost');

    $this->assertAuthenticatedAs($this->member, 'web');

    expect(session(EnsureTenantMember::SESSION_KEY))->toBe($this->acme->id)
        ->and($this->member->refresh()->last_login_at)->not->toBeNull();

    $this->get('http://acme.localhost/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Dashboard')
            ->where('auth.user.email', $this->member->email)
            ->where('auth.membership.isOwner', false)
            ->where('tenant.slug', 'acme'));
});

it('ignores the case of the email address', function () {
    ($this->signIn)($this->acme, strtoupper($this->member->email))->assertRedirect('http://acme.localhost');

    $this->assertAuthenticatedAs($this->member, 'web');
});

it('sends someone who is already signed in away from the sign-in page', function () {
    $this->actingAsMember($this->acme, $this->member)
        ->get('http://acme.localhost/login')
        ->assertRedirect('http://acme.localhost');
});

it('fails identically for a wrong password, an unknown address, a non-member and a suspended member', function () {
    $outsider = $this->addMember($this->globex, Role::ADMIN);
    $suspended = $this->addMember($this->acme, Role::EMPLOYEE, status: MembershipStatus::Suspended);

    $attempts = [
        'wrong password' => [$this->member->email, 'not-the-password'],
        'unknown address' => ['nobody@example.test', 'password'],
        'member of another workspace only' => [$outsider->email, 'password'],
        'suspended member' => [$suspended->email, 'password'],
    ];

    $outcomes = [];

    foreach ($attempts as $case => [$email, $password]) {
        $response = $this->from('http://acme.localhost/login')
            ->post('http://acme.localhost/login', ['email' => $email, 'password' => $password]);

        $response->assertRedirect('http://acme.localhost/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest('web');

        $outcomes[$case] = [
            $response->getStatusCode(),
            $response->headers->get('Location'),
            session('errors')->getBag('default')->toArray(),
        ];

        $this->startNewBrowserSession();
    }

    // Nothing in the response tells the four cases apart.
    expect(array_unique(array_map('serialize', $outcomes)))->toHaveCount(1);

    expect($this->auditTrail($this->acme, 'auth.login_failed'))->toHaveCount(4);
});

it('takes the same minimum time whether or not the account exists', function () {
    ($this->signIn)($this->acme, 'nobody@example.test');
    ($this->signIn)($this->acme, $this->member->email, 'wrong');

    // The verifier pads each check up to 250 ms (the sleep is faked in tests).
    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn (CarbonInterval $pause): bool => $pause->totalMilliseconds > 0 && $pause->totalMilliseconds <= 250, 2);
});

it('does not let a member of one workspace sign in to another', function () {
    ($this->signIn)($this->globex, $this->member->email)
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    $this->assertGuest('web');
    expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull();

    // The very same credentials are fine at home.
    ($this->signIn)($this->acme, $this->member->email)->assertRedirect('http://acme.localhost');
});

it('signs the same person in to each workspace they belong to, separately', function () {
    $this->addMember($this->globex, Role::MANAGER, $this->member);

    ($this->signIn)($this->globex, $this->member->email)->assertRedirect('http://globex.localhost');

    expect(session(EnsureTenantMember::SESSION_KEY))->toBe($this->globex->id);
});

it('validates the sign-in form', function (array $input, string $field) {
    $this->post('http://acme.localhost/login', $input)->assertSessionHasErrors($field);

    $this->assertGuest('web');
})->with([
    'no email' => [['password' => 'password'], 'email'],
    'not an email' => [['email' => 'not-an-email', 'password' => 'password'], 'email'],
    'no password' => [['email' => 'someone@example.test'], 'password'],
    'remember is not a boolean' => [['email' => 'someone@example.test', 'password' => 'password', 'remember' => 'maybe'], 'remember'],
]);

describe('throttling', function () {
    it('locks an address out after five failures, even for the right password', function () {
        foreach (range(1, 5) as $attempt) {
            ($this->signIn)($this->acme, $this->member->email, 'wrong')
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        }

        ($this->signIn)($this->acme, $this->member->email)
            ->assertSessionHasErrors('email');

        expect(session('errors')->first('email'))->toStartWith('Too many login attempts.');
        $this->assertGuest('web');

        // The lock-out itself is not counted as another failed attempt.
        expect($this->auditTrail($this->acme, 'auth.login_failed'))->toHaveCount(5);
    });

    it('lifts the lock-out after a minute', function () {
        foreach (range(1, 5) as $attempt) {
            ($this->signIn)($this->acme, $this->member->email, 'wrong');
        }

        $this->travel(61)->seconds();

        ($this->signIn)($this->acme, $this->member->email)->assertRedirect('http://acme.localhost');
        $this->assertAuthenticatedAs($this->member, 'web');
    });

    it('counts per address and per workspace', function () {
        $colleague = $this->addMember($this->acme, Role::EMPLOYEE);
        $this->addMember($this->globex, Role::EMPLOYEE, $this->member);

        foreach (range(1, 5) as $attempt) {
            ($this->signIn)($this->acme, $this->member->email, 'wrong');
        }

        // Someone else on the same machine is not locked out ...
        ($this->signIn)($this->acme, $colleague->email)->assertRedirect('http://acme.localhost');
        $this->assertAuthenticatedAs($colleague, 'web');
        $this->startNewBrowserSession();

        // ... and neither is the same person in their other workspace.
        ($this->signIn)($this->globex, $this->member->email)->assertRedirect('http://globex.localhost');
        $this->assertAuthenticatedAs($this->member, 'web');
    });

    it('starts counting again after a successful sign-in', function () {
        foreach (range(1, 4) as $attempt) {
            ($this->signIn)($this->acme, $this->member->email, 'wrong');
        }

        ($this->signIn)($this->acme, $this->member->email)->assertRedirect();
        $this->post('http://acme.localhost/logout');

        foreach (range(1, 4) as $attempt) {
            ($this->signIn)($this->acme, $this->member->email, 'wrong')
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        }

        ($this->signIn)($this->acme, $this->member->email)->assertRedirect('http://acme.localhost');
    });
});

it('signs out, ends the session and records it', function () {
    ($this->signIn)($this->acme, $this->member->email);
    $token = session()->token();

    $this->post('http://acme.localhost/logout')->assertRedirect('http://acme.localhost/login');

    $this->assertGuest('web');

    expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull()
        ->and(session()->token())->not->toBe($token);

    $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');

    $entry = $this->auditTrail($this->acme, 'auth.logout')->sole();

    expect($entry->actor_id)->toBe($this->member->id)
        ->and($entry->actor_type)->toBe('user');
});

it('only signs out by POST', function () {
    $this->actingAsMember($this->acme, $this->member)->get('http://acme.localhost/logout')->assertStatus(405);

    $this->assertAuthenticatedAs($this->member, 'web');
});

it('does not accept a platform account at a workspace', function () {
    $admin = $this->createPlatformUser();

    ($this->signIn)($this->acme, $admin->email)
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    $this->assertGuest('web');
    $this->assertGuest('platform');

    // Nor does a platform session open the workspace.
    $this->actingAsPlatformUser($admin)
        ->get('http://acme.localhost/')
        ->assertRedirect('http://acme.localhost/login');
});

it('does not create an account for a platform user who shares an address with nobody', function () {
    $this->createPlatformUser();

    expect(User::query()->count())->toBe(3); // the two owners and the member
});
