<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->member = $this->addMember($this->acme, Role::MANAGER);
    $this->recoveryCodes = $this->enableTwoFactor($this->member);

    $this->passwordStep = fn (array $extra = []): TestResponse => $this->post('http://acme.localhost/login', [
        'email' => $this->member->email,
        'password' => 'password',
        ...$extra,
    ]);

    $this->challenge = fn (array $input): TestResponse => $this
        ->from('http://acme.localhost/two-factor-challenge')
        ->post('http://acme.localhost/two-factor-challenge', $input);
});

it('asks for the second factor instead of signing in', function () {
    ($this->passwordStep)()->assertRedirect('http://acme.localhost/two-factor-challenge');

    // Not authenticated, not bound to the workspace, no audit entry yet.
    $this->assertGuest('web');

    expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull()
        ->and($this->auditTrail($this->acme, 'auth.login'))->toHaveCount(0);

    $this->get('http://acme.localhost/two-factor-challenge')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Core/Pages/Auth/TwoFactorChallenge'));

    // The password alone opens nothing.
    $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');
});

it('sends the challenge page back to sign-in when no password step came first', function () {
    $this->get('http://acme.localhost/two-factor-challenge')->assertRedirect('http://acme.localhost/login');

    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost/login');

    $this->assertGuest('web');
});

it('signs in with a valid code', function () {
    ($this->passwordStep)();

    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost');

    $this->assertAuthenticatedAs($this->member, 'web');

    expect(session(EnsureTenantMember::SESSION_KEY))->toBe($this->acme->id)
        ->and(session('auth.pending_two_factor.web'))->toBeNull()
        ->and($this->auditTrail($this->acme, 'auth.login')->sole()->properties)->toBe(['method' => 'two_factor']);

    $this->get('http://acme.localhost/')->assertOk();
});

it('accepts a code typed with spaces', function () {
    ($this->passwordStep)();

    $code = $this->twoFactorCode($this->member);

    ($this->challenge)(['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertRedirect('http://acme.localhost');

    $this->assertAuthenticatedAs($this->member, 'web');
});

it('rejects a wrong code and keeps waiting', function () {
    ($this->passwordStep)();

    ($this->challenge)(['code' => $this->wrongTwoFactorCode($this->member)])
        ->assertRedirect('http://acme.localhost/two-factor-challenge')
        ->assertSessionHasErrors('code');

    $this->assertGuest('web');

    // Still pending: the right code finishes the sign-in.
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost');
    $this->assertAuthenticatedAs($this->member, 'web');
});

it('rejects malformed codes', function (string $code) {
    ($this->passwordStep)();

    ($this->challenge)(['code' => $code])->assertSessionHasErrors('code');

    $this->assertGuest('web');
})->with(['letters' => ['abcdef'], 'too short' => ['12345'], 'too long' => ['1234567'], 'too long for the field' => ['12345678901']]);

it('requires a code or a recovery code', function () {
    ($this->passwordStep)();

    ($this->challenge)([])->assertSessionHasErrors(['code', 'recovery_code']);

    $this->assertGuest('web');
});

it('does not accept the same code twice', function () {
    $code = $this->twoFactorCode($this->member);

    ($this->passwordStep)();
    ($this->challenge)(['code' => $code])->assertRedirect('http://acme.localhost');
    $this->post('http://acme.localhost/logout');

    // Someone who watched that code being typed, and knows the password, replays it.
    ($this->passwordStep)()->assertRedirect('http://acme.localhost/two-factor-challenge');
    ($this->challenge)(['code' => $code])->assertSessionHasErrors('code');

    $this->assertGuest('web');

    // The authenticator's next code is fine.
    ($this->challenge)(['code' => $this->twoFactorCode($this->member, stepsAhead: 1)])->assertRedirect('http://acme.localhost');
    $this->assertAuthenticatedAs($this->member, 'web');
});

it('does not accept a code replayed in another workspace', function () {
    $this->addMember($this->globex, Role::EMPLOYEE, $this->member);
    $code = $this->twoFactorCode($this->member);

    ($this->passwordStep)();
    ($this->challenge)(['code' => $code])->assertRedirect('http://acme.localhost');
    $this->startNewBrowserSession();

    // Replay protection is platform-wide, not per tenant cache.
    $this->post('http://globex.localhost/login', ['email' => $this->member->email, 'password' => 'password'])
        ->assertRedirect('http://globex.localhost/two-factor-challenge');
    $this->post('http://globex.localhost/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');

    $this->assertGuest('web');
});

it('signs in with a recovery code and burns it', function () {
    ($this->passwordStep)();

    $code = $this->recoveryCodes[2];

    ($this->challenge)(['recovery_code' => $code])->assertRedirect('http://acme.localhost');

    $this->assertAuthenticatedAs($this->member, 'web');

    $remaining = $this->member->refresh()->recoveryCodes();

    expect($remaining)->toHaveCount(count($this->recoveryCodes) - 1)
        ->not->toContain($code)
        ->and($this->auditTrail($this->acme, 'auth.login')->sole()->properties)->toBe(['method' => 'recovery_code']);

    // Used once, gone for good.
    $this->post('http://acme.localhost/logout');
    ($this->passwordStep)();
    ($this->challenge)(['recovery_code' => $code])->assertSessionHasErrors('recovery_code');
    $this->assertGuest('web');

    // The others still work.
    ($this->challenge)(['recovery_code' => $this->recoveryCodes[0]])->assertRedirect('http://acme.localhost');
    $this->assertAuthenticatedAs($this->member, 'web');
});

it('rejects a recovery code that is not one of the account\'s', function () {
    $stranger = $this->addMember($this->acme, Role::EMPLOYEE);
    $theirs = $this->enableTwoFactor($stranger);

    ($this->passwordStep)();

    ($this->challenge)(['recovery_code' => $theirs[0]])->assertSessionHasErrors('recovery_code');
    ($this->challenge)(['recovery_code' => 'aaaaa-bbbbb'])->assertSessionHasErrors('recovery_code');

    $this->assertGuest('web');
    expect($stranger->refresh()->recoveryCodes())->toHaveCount(count($theirs));
});

it('discards the pending sign-in after five wrong codes', function () {
    ($this->passwordStep)();

    $wrong = $this->wrongTwoFactorCode($this->member);

    foreach (range(1, 4) as $attempt) {
        ($this->challenge)(['code' => $wrong])
            ->assertRedirect('http://acme.localhost/two-factor-challenge')
            ->assertSessionHasErrors('code');
    }

    ($this->challenge)(['code' => $wrong])
        ->assertRedirect('http://acme.localhost/login')
        ->assertSessionHasErrors(['email' => 'Too many incorrect codes. Please sign in again.']);

    expect(session('auth.pending_two_factor.web'))->toBeNull();

    // Even the right code is useless now: the password step has to be repeated.
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost/login');
    $this->assertGuest('web');

    ($this->passwordStep)()->assertRedirect('http://acme.localhost/two-factor-challenge');
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost');
    $this->assertAuthenticatedAs($this->member, 'web');
});

it('expires a pending sign-in that waits too long', function () {
    ($this->passwordStep)();

    $this->travel(config('platform.two_factor.challenge_ttl') - 5)->seconds();
    $this->get('http://acme.localhost/two-factor-challenge')->assertOk();

    $this->travel(10)->seconds();

    $this->get('http://acme.localhost/two-factor-challenge')->assertRedirect('http://acme.localhost/login');
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost/login');

    $this->assertGuest('web');
    expect(session('auth.pending_two_factor.web'))->toBeNull();
});

it('refuses the code when the member was removed or suspended after the password step', function (string $how) {
    ($this->passwordStep)();

    $membership = $this->membershipOf($this->acme, $this->member);

    match ($how) {
        'suspended' => $membership->update(['status' => MembershipStatus::Suspended]),
        'removed' => $this->inTenant($this->acme, fn () => $membership->delete()),
    };

    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost/login');

    $this->assertGuest('web');
    expect(session('auth.pending_two_factor.web'))->toBeNull();
})->with(['suspended', 'removed']);

it('does not finish in a workspace the person is not a member of', function () {
    ($this->passwordStep)();

    // The half-finished sign-in from acme, presented to globex.
    $this->post('http://globex.localhost/two-factor-challenge', ['code' => $this->twoFactorCode($this->member)])
        ->assertRedirect('http://globex.localhost/login');

    $this->assertGuest('web');
});

it('keeps "remember me" across the challenge', function () {
    $recaller = Auth::guard('web')->getRecallerName();

    expect(($this->passwordStep)(['remember' => true])->getCookie($recaller))->toBeNull();

    $response = ($this->challenge)(['code' => $this->twoFactorCode($this->member)]);

    expect($response->getCookie($recaller))->not->toBeNull();
});

it('verifies nothing for an account without a confirmed second factor', function () {
    $service = app(TwoFactorService::class);
    $plain = $this->addMember($this->acme, Role::EMPLOYEE);

    expect($service->verify($plain, '123456'))->toBeFalse();

    // Enrolment started but never confirmed: sign-in is still password only.
    $service->enable($plain);
    expect($plain->hasEnabledTwoFactor())->toBeFalse();

    $this->post('http://acme.localhost/login', ['email' => $plain->email, 'password' => 'password'])
        ->assertRedirect('http://acme.localhost');
    $this->assertAuthenticatedAs($plain, 'web');
});

it('stops accepting codes for an account that keeps guessing, however often the password step is repeated', function () {
    $wrong = $this->wrongTwoFactorCode($this->member);

    // Each round is a fresh sign-in: knowing the password buys five more guesses, over and over.
    foreach (range(1, 2) as $round) {
        ($this->passwordStep)()->assertRedirect('http://acme.localhost/two-factor-challenge');

        foreach (range(1, 5) as $guess) {
            ($this->challenge)(['code' => $wrong]);
        }
    }

    $this->assertGuest('web');

    // Ten wrong codes later even the right one is refused ...
    ($this->passwordStep)();
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertSessionHasErrors('code');
    $this->assertGuest('web');

    // ... in every workspace the account belongs to, not just this one ...
    $this->addMember($this->globex, Role::EMPLOYEE, $this->member);
    $this->startNewBrowserSession();
    $this->post('http://globex.localhost/login', ['email' => $this->member->email, 'password' => 'password']);
    $this->post('http://globex.localhost/two-factor-challenge', ['code' => $this->twoFactorCode($this->member)])
        ->assertSessionHasErrors('code');
    $this->assertGuest('web');

    // ... while the account's owner can still get in with a recovery code,
    $this->startNewBrowserSession();
    ($this->passwordStep)();
    ($this->challenge)(['recovery_code' => $this->recoveryCodes[0]])->assertRedirect('http://acme.localhost');
    $this->assertAuthenticatedAs($this->member, 'web');

    // and codes work again once the lock has run out.
    $this->post('http://acme.localhost/logout');
    $this->travel(config('platform.two_factor.lockout_seconds') + 1)->seconds();

    ($this->passwordStep)();
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost');
    $this->assertAuthenticatedAs($this->member, 'web');
});

it('forgets earlier slips once a code is accepted', function () {
    $wrong = $this->wrongTwoFactorCode($this->member);

    // Eight wrong codes over two sign-ins, then the right one - twice over.
    // Sixteen slips in total, but never ten without a success in between.
    foreach ([0, 1] as $stepsAhead) {
        foreach (range(1, 2) as $signIn) {
            ($this->passwordStep)();

            foreach (range(1, 4) as $slip) {
                ($this->challenge)(['code' => $wrong])->assertSessionHasErrors('code');
            }
        }

        ($this->challenge)(['code' => $this->twoFactorCode($this->member, $stepsAhead)])
            ->assertRedirect('http://acme.localhost');

        $this->assertAuthenticatedAs($this->member, 'web');
        $this->post('http://acme.localhost/logout');
    }
});

it('does not count malformed input as a guess', function () {
    ($this->passwordStep)();

    // Typos that cannot be a code at all are rejected without touching the account's allowance.
    foreach (range(1, 4) as $attempt) {
        ($this->challenge)(['code' => 'abc'])->assertSessionHasErrors('code');
    }

    foreach (range(1, 3) as $signIn) {
        ($this->passwordStep)();

        foreach (range(1, 4) as $attempt) {
            ($this->challenge)(['code' => '12345'])->assertSessionHasErrors('code');
        }
    }

    ($this->passwordStep)();
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])->assertRedirect('http://acme.localhost');
});

it('names the recovery code when that is what was wrong', function () {
    ($this->passwordStep)();

    ($this->challenge)(['recovery_code' => 'aaaaa-bbbbb'])
        ->assertSessionHasErrors(['recovery_code' => 'That recovery code is not valid, or has already been used.']);
    ($this->challenge)(['code' => $this->wrongTwoFactorCode($this->member)])
        ->assertSessionHasErrors(['code' => 'That code is not valid. Check your authenticator app and try again.']);
});

it('tells a locked account to use a recovery code rather than calling a right code wrong', function () {
    $wrong = $this->wrongTwoFactorCode($this->member);

    foreach (range(1, 2) as $round) {
        ($this->passwordStep)();

        foreach (range(1, 5) as $guess) {
            ($this->challenge)(['code' => $wrong]);
        }
    }

    ($this->passwordStep)();
    ($this->challenge)(['code' => $this->twoFactorCode($this->member)])
        ->assertSessionHasErrors(['code' => 'Too many wrong codes. Use a recovery code, or try again later.']);

    $this->assertGuest('web');
});
