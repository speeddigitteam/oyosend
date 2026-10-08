<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Events\MemberJoined;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->globex = $this->createTenant('globex', ['name' => 'Globex']);

    $this->newAccount = ['name' => 'Nadia Islam', 'password' => 'a-good-password', 'password_confirmation' => 'a-good-password'];

    $this->members = fn () => TenantMembership::forTenant($this->acme)->count();
});

describe('the invitation page', function () {
    it('offers to create an account to someone new', function () {
        [$invitation, $token] = $this->createInvitation($this->acme, 'new@example.test', Role::MANAGER);

        $this->get("http://acme.localhost/invitations/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Auth/AcceptInvitation')
                ->where('workspace', 'Acme Trading')
                ->where('state', 'new_account')
                ->where('token', $token)
                ->where('invitation.email', 'new@example.test')
                ->where('invitation.role', 'Manager')
                ->where('invitation.inviter', $this->ownerOf($this->acme)->name)
                ->where('invitation.expiresAt', $invitation->expires_at->toIso8601String()));
    });

    it('asks someone who already has an account for their password', function () {
        $existing = $this->addMember($this->globex, Role::EMPLOYEE);
        [, $token] = $this->createInvitation($this->acme, $existing->email);

        $this->get("http://acme.localhost/invitations/{$token}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'existing_account'));
    });

    it('recognises the invitee when they are signed in, and anyone else as the wrong account', function () {
        $invitee = User::factory()->create();
        $someoneElse = $this->addMember($this->acme, Role::EMPLOYEE);
        [, $token] = $this->createInvitation($this->acme, $invitee->email);

        $this->actingAs($invitee, 'web')
            ->get("http://acme.localhost/invitations/{$token}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm'));

        $this->actingAsMember($this->acme, $someoneElse)
            ->get("http://acme.localhost/invitations/{$token}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'wrong_account'));
    });

    it('says why a link no longer works, without the invitation\'s details', function (string $state, array $attributes) {
        [, $token] = $this->createInvitation($this->acme, 'new@example.test', attributes: $attributes);

        $this->get("http://acme.localhost/invitations/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', $state)
                ->where('workspace', 'Acme Trading')
                ->missing('invitation')
                ->missing('token'));
    })->with([
        'expired' => ['expired', ['expires_at' => now()->subMinute()]],
        'revoked' => ['revoked', ['revoked_at' => now()]],
        'already used' => ['accepted', ['accepted_at' => now()]],
    ]);

    it('treats an unknown token as invalid and a malformed one as no page at all', function () {
        $this->get('http://acme.localhost/invitations/'.str_repeat('z', 64))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid')->missing('invitation'));

        $this->get('http://acme.localhost/invitations/short')->assertNotFound();
        $this->get('http://acme.localhost/invitations/'.str_repeat('-', 64))->assertNotFound();
        $this->post('http://acme.localhost/invitations/'.str_repeat('a', 65), $this->newAccount)->assertNotFound();
    });
});

describe('accepting as a new account', function () {
    it('creates a verified account, adds it to the workspace with the invited role and signs it in', function () {
        Event::fake([MemberJoined::class]);

        [$invitation, $token] = $this->createInvitation($this->acme, 'new@example.test', Role::MANAGER);

        $this->post("http://acme.localhost/invitations/{$token}", $this->newAccount)
            ->assertRedirect('http://acme.localhost')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $user = User::query()->where('email', 'new@example.test')->sole();

        expect($user->name)->toBe('Nadia Islam')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('a-good-password', $user->password))->toBeTrue();

        $membership = $this->membershipOf($this->acme, $user);

        expect($membership->status)->toBe(MembershipStatus::Active)
            ->and($membership->is_owner)->toBeFalse()
            ->and($membership->joined_at)->not->toBeNull()
            ->and($invitation->refresh()->accepted_at)->not->toBeNull();

        // Exactly the invited role, in this workspace only.
        expect($this->inTenant($this->acme, fn (): array => $user->roles()->pluck('name')->all()))->toBe(['Manager'])
            ->and($this->inTenant($this->globex, fn (): array => $user->roles()->pluck('name')->all()))->toBe([])
            ->and($this->inTenant($this->acme, fn (): bool => Gate::forUser($user)->allows('core.members.invite')))->toBeTrue()
            ->and($this->inTenant($this->acme, fn (): bool => Gate::forUser($user)->allows('core.roles.manage')))->toBeFalse();

        // Signed in, in a session bound to this workspace.
        $this->assertAuthenticatedAs($user, 'web');
        expect(session(EnsureTenantMember::SESSION_KEY))->toBe($this->acme->id);
        $this->get('http://acme.localhost/')->assertOk();

        $joined = $this->auditTrail($this->acme, 'member.joined')->sole();

        expect($joined->actor_id)->toBe($user->id)
            ->and($joined->subject_id)->toBe($membership->id)
            ->and($joined->subject_label)->toBe('new@example.test')
            ->and($joined->properties)->toBe(['role' => 'Manager'])
            ->and($this->auditTrail($this->acme, 'auth.login')->sole()->properties)->toBe(['method' => 'invitation'])
            ->and($this->auditTrail($this->acme)->toJson())->not->toContain('a-good-password', $token);

        Event::assertDispatched(MemberJoined::class, fn (MemberJoined $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'membership_id' => $membership->id,
                'user_id' => $user->id,
                'name' => 'Nadia Islam',
                'email' => 'new@example.test',
                'role' => 'Manager',
            ]);
    });

    it('lets the new person sign in with their password afterwards', function () {
        [, $token] = $this->createInvitation($this->acme, 'new@example.test');

        $this->post("http://acme.localhost/invitations/{$token}", $this->newAccount);
        $this->post('http://acme.localhost/logout');

        $this->post('http://acme.localhost/login', ['email' => 'new@example.test', 'password' => 'a-good-password'])
            ->assertRedirect('http://acme.localhost');
    });

    it('validates the new account and creates nothing when it is not valid', function (array $override, string $field) {
        [$invitation, $token] = $this->createInvitation($this->acme, 'new@example.test');

        $this->post("http://acme.localhost/invitations/{$token}", [...$this->newAccount, ...$override])
            ->assertSessionHasErrors($field);

        $this->assertGuest('web');

        expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse()
            ->and($invitation->refresh()->isPending())->toBeTrue();
    })->with([
        'no name' => [['name' => ''], 'name'],
        'name too long' => [['name' => str_repeat('a', 121)], 'name'],
        'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
        'unconfirmed password' => [['password_confirmation' => 'different'], 'password'],
    ]);

    it('ignores an email address or role smuggled into the form', function () {
        [, $token] = $this->createInvitation($this->acme, 'new@example.test', Role::EMPLOYEE);

        $this->post("http://acme.localhost/invitations/{$token}", [
            ...$this->newAccount,
            'email' => 'attacker@example.test',
            'role_id' => $this->roleOf($this->acme, Role::ADMIN)->id,
            'is_owner' => true,
        ])->assertRedirect('http://acme.localhost');

        $user = User::query()->where('email', 'new@example.test')->sole();

        expect(User::query()->where('email', 'attacker@example.test')->exists())->toBeFalse()
            ->and($this->membershipOf($this->acme, $user)->is_owner)->toBeFalse()
            ->and($this->inTenant($this->acme, fn (): array => $user->roles()->pluck('name')->all()))->toBe(['Employee']);
    });
});

describe('accepting with an existing account', function () {
    beforeEach(function () {
        // Someone who already works in globex is invited to acme.
        $this->existing = $this->addMember($this->globex, Role::ADMIN);
        [$this->invitation, $this->token] = $this->createInvitation($this->acme, $this->existing->email, Role::EMPLOYEE);
    });

    it('requires the account\'s password', function () {
        $this->post("http://acme.localhost/invitations/{$this->token}", [])->assertSessionHasErrors('password');

        $this->post("http://acme.localhost/invitations/{$this->token}", ['password' => 'not-my-password'])
            ->assertSessionHasErrors(['password' => 'The provided password is incorrect.']);

        // Offering to "create" the account again does not get around it.
        $this->post("http://acme.localhost/invitations/{$this->token}", [...$this->newAccount, 'password' => 'a-good-password'])
            ->assertSessionHasErrors('password');

        $this->assertGuest('web');

        expect(($this->members)())->toBe(1)
            ->and($this->invitation->refresh()->isPending())->toBeTrue()
            ->and(Hash::check('password', $this->existing->refresh()->password))->toBeTrue()
            ->and(User::query()->where('email', $this->existing->email)->count())->toBe(1);
    });

    it('adds the same account to the workspace and signs it in here', function () {
        $this->post("http://acme.localhost/invitations/{$this->token}", ['password' => 'password'])
            ->assertRedirect('http://acme.localhost');

        $this->assertAuthenticatedAs($this->existing, 'web');

        expect(session(EnsureTenantMember::SESSION_KEY))->toBe($this->acme->id)
            ->and($this->existing->memberships()->count())->toBe(2)
            ->and(User::query()->where('email', $this->existing->email)->count())->toBe(1)
            ->and($this->existing->refresh()->name)->not->toBe('Nadia Islam');

        // Admin in globex, plain employee here: roles do not travel between workspaces.
        expect($this->inTenant($this->acme, fn (): array => $this->existing->roles()->pluck('name')->all()))->toBe(['Employee'])
            ->and($this->inTenant($this->globex, fn (): array => $this->existing->roles()->pluck('name')->all()))->toBe(['Admin'])
            ->and($this->inTenant($this->acme, fn (): bool => Gate::forUser($this->existing)->allows('core.roles.manage')))->toBeFalse()
            ->and($this->inTenant($this->globex, fn (): bool => Gate::forUser($this->existing)->allows('core.roles.manage')))->toBeTrue();
    });

    it('accepts an account whose address is stored in a different case', function () {
        $mixed = User::factory()->create(['email' => 'Mixed.Case@Example.TEST']);
        [, $token] = $this->createInvitation($this->acme, 'mixed.case@example.test');

        $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'password'])
            ->assertRedirect('http://acme.localhost')
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($mixed, 'web');
        expect($this->membershipOf($this->acme, $mixed)->status)->toBe(MembershipStatus::Active);
    });

    it('still demands the second factor: joining a workspace is not a way around it', function () {
        $this->enableTwoFactor($this->existing);

        $this->post("http://acme.localhost/invitations/{$this->token}", ['password' => 'password'])
            ->assertRedirect('http://acme.localhost/two-factor-challenge');

        // A member now, but not signed in.
        $this->assertGuest('web');

        expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull()
            ->and($this->membershipOf($this->acme, $this->existing)->status)->toBe(MembershipStatus::Active);

        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');

        $this->post('http://acme.localhost/two-factor-challenge', ['code' => $this->wrongTwoFactorCode($this->existing)])
            ->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $this->post('http://acme.localhost/two-factor-challenge', ['code' => $this->twoFactorCode($this->existing)])
            ->assertRedirect('http://acme.localhost');
        $this->assertAuthenticatedAs($this->existing, 'web');
    });
});

describe('accepting while signed in', function () {
    it('joins straight away when the signed-in person is the invitee', function () {
        // A former member whose session is still open, invited back.
        $returning = User::factory()->create();
        [, $token] = $this->createInvitation($this->acme, $returning->email, Role::MANAGER);

        $this->actingAsMember($this->acme, $returning)
            ->post("http://acme.localhost/invitations/{$token}")
            ->assertRedirect('http://acme.localhost')
            ->assertSessionHasNoErrors();

        expect($this->membershipOf($this->acme, $returning)->status)->toBe(MembershipStatus::Active);

        $this->get('http://acme.localhost/')->assertOk();
    });

    it('refuses when someone else is signed in', function () {
        $someoneElse = $this->addMember($this->acme, Role::ADMIN);
        [$invitation, $token] = $this->createInvitation($this->acme, 'new@example.test');

        $this->actingAsMember($this->acme, $someoneElse)
            ->post("http://acme.localhost/invitations/{$token}", $this->newAccount)
            ->assertSessionHasErrors(['invitation' => 'This invitation was sent to a different email address.']);

        expect($invitation->refresh()->isPending())->toBeTrue()
            ->and(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
    });
});

describe('links that must not work', function () {
    it('rejects an expired, revoked or already used invitation', function (array $attributes) {
        [, $token] = $this->createInvitation($this->acme, 'new@example.test', attributes: $attributes);

        $this->post("http://acme.localhost/invitations/{$token}", $this->newAccount)
            ->assertSessionHasErrors(['invitation' => 'This invitation is no longer valid.']);

        $this->assertGuest('web');

        expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse()
            ->and(($this->members)())->toBe(1);
    })->with([
        'expired' => [['expires_at' => now()->subMinute()]],
        'revoked' => [['revoked_at' => now()]],
        'already used' => [['accepted_at' => now()]],
    ]);

    it('rejects an invitation that expires between opening the page and submitting it', function () {
        [, $token] = $this->createInvitation($this->acme, 'new@example.test');

        $this->get("http://acme.localhost/invitations/{$token}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'new_account'));

        $this->travel(8)->days();

        $this->post("http://acme.localhost/invitations/{$token}", $this->newAccount)->assertSessionHasErrors('invitation');
        $this->assertGuest('web');
    });

    it('works once', function () {
        [, $token] = $this->createInvitation($this->acme, 'new@example.test');

        $this->post("http://acme.localhost/invitations/{$token}", $this->newAccount)->assertRedirect('http://acme.localhost');
        $this->startNewBrowserSession();

        $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'a-good-password'])
            ->assertSessionHasErrors('invitation');

        $this->assertGuest('web');
        expect(($this->members)())->toBe(2);
    });

    it('rejects a token that was never issued', function () {
        $this->post('http://acme.localhost/invitations/'.str_repeat('a', 64), $this->newAccount)
            ->assertSessionHasErrors('invitation');

        expect(User::query()->where('name', 'Nadia Islam')->exists())->toBeFalse();
    });

    it('does not accept one workspace\'s invitation on another workspace\'s host', function () {
        [$invitation, $token] = $this->createInvitation($this->acme, 'new@example.test', Role::ADMIN);

        // The link is real - but it is acme's, and this is globex.
        $this->get("http://globex.localhost/invitations/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'invalid')
                ->where('workspace', 'Globex')
                ->missing('invitation'));

        $this->post("http://globex.localhost/invitations/{$token}", $this->newAccount)
            ->assertSessionHasErrors('invitation');

        $this->assertGuest('web');

        expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse()
            ->and(TenantMembership::forTenant($this->globex)->count())->toBe(1)
            ->and(($this->members)())->toBe(1)
            ->and($invitation->refresh()->isPending())->toBeTrue();

        // It still works where it belongs.
        $this->post("http://acme.localhost/invitations/{$token}", $this->newAccount)->assertRedirect('http://acme.localhost');
    });

    it('does not add someone who became a member some other way in the meantime', function () {
        $user = User::factory()->create();
        [, $token] = $this->createInvitation($this->acme, $user->email);

        $this->addMember($this->acme, Role::EMPLOYEE, $user);

        $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'password'])
            ->assertSessionHasErrors(['invitation' => 'You are already a member of this workspace.']);

        expect(TenantMembership::forTenant($this->acme)->where('user_id', $user->id)->count())->toBe(1);
    });

    it('limits how often a link can be tried', function () {
        $existing = $this->addMember($this->globex, Role::EMPLOYEE);
        [, $token] = $this->createInvitation($this->acme, $existing->email);

        foreach (range(1, 10) as $attempt) {
            $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'guess-'.$attempt])
                ->assertSessionHasErrors('password');
        }

        $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'password'])->assertTooManyRequests();
        $this->assertGuest('web');
    });
});

it('recognises a signed-in invitee whose address is stored in a different case', function () {
    $mixed = User::factory()->create(['email' => 'Mixed.Case@Example.TEST']);
    [, $token] = $this->createInvitation($this->acme, 'mixed.case@example.test');

    $this->actingAsMember($this->acme, $mixed)
        ->get("http://acme.localhost/invitations/{$token}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm'));

    $this->post("http://acme.localhost/invitations/{$token}")->assertRedirect('http://acme.localhost')->assertSessionHasNoErrors();

    expect($this->membershipOf($this->acme, $mixed)->status)->toBe(MembershipStatus::Active);
});
