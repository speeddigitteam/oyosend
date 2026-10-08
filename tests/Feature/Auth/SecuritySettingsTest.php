<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\Hash;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->member = $this->addMember($this->acme, Role::EMPLOYEE);

    $this->actingAsMember($this->acme, $this->member);
});

describe('changing the password', function () {
    it('requires the current password', function () {
        $this->put('http://acme.localhost/settings/password', [
            'current_password' => 'not-my-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('current_password');

        $this->put('http://acme.localhost/settings/password', [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('current_password');

        expect(Hash::check('password', $this->member->refresh()->password))->toBeTrue()
            ->and($this->auditTrail($this->acme, 'auth.password_changed'))->toHaveCount(0);
    });

    it('changes the password, keeps this session and records it', function () {
        $this->put('http://acme.localhost/settings/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');

        expect(Hash::check('a-brand-new-password', $this->member->refresh()->password))->toBeTrue();

        // Still signed in here ...
        $this->get('http://acme.localhost/settings/security')->assertOk();

        // ... and the old password is dead.
        $this->startNewBrowserSession();
        $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'a-brand-new-password'])
            ->assertRedirect('http://acme.localhost');

        $entry = $this->auditTrail($this->acme, 'auth.password_changed')->sole();

        expect($entry->actor_id)->toBe($this->member->id)
            ->and(json_encode($entry))->not->toContain('a-brand-new-password');
    });

    it('validates the new password', function (array $override) {
        $this->put('http://acme.localhost/settings/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
            ...$override,
        ])->assertSessionHasErrors('password');

        expect(Hash::check('password', $this->member->refresh()->password))->toBeTrue();
    })->with([
        'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
        'not confirmed' => [['password_confirmation' => 'something-else']],
        'same as the current one' => [['password' => 'password', 'password_confirmation' => 'password']],
    ]);

    it('is only for signed-in members', function () {
        $this->startNewBrowserSession();

        $this->put('http://acme.localhost/settings/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect('http://acme.localhost/login');

        expect(Hash::check('password', $this->member->refresh()->password))->toBeTrue();
    });
});

describe('two-factor authentication', function () {
    it('starts off', function () {
        $this->get('http://acme.localhost/settings/security')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Settings/Security')
                ->where('twoFactor.enabled', false)
                ->where('twoFactor.pending', false)
                ->where('twoFactor.qrCode', null)
                ->where('twoFactor.secret', null)
                ->where('twoFactor.recoveryCodesRemaining', null));
    });

    it('needs the current password to start enrolment', function () {
        $this->post('http://acme.localhost/settings/two-factor', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->post('http://acme.localhost/settings/two-factor')
            ->assertSessionHasErrors('current_password');

        expect($this->member->refresh()->two_factor_secret)->toBeNull();
    });

    it('goes from enrolment to confirmation to recovery codes, and can be switched off', function () {
        // 1. Start: a secret exists, but two-factor is not on yet.
        $this->post('http://acme.localhost/settings/two-factor', ['current_password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->member->refresh();

        expect($this->member->two_factor_secret)->toBeString()->toHaveLength(32)
            ->and($this->member->hasEnabledTwoFactor())->toBeFalse()
            ->and($this->member->recoveryCodes())->toHaveCount(8);

        $this->get('http://acme.localhost/settings/security')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('twoFactor.enabled', false)
                ->where('twoFactor.pending', true)
                ->where('twoFactor.secret', $this->member->two_factor_secret)
                ->where('twoFactor.qrCode', fn (string $svg): bool => str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'))
                ->where('twoFactor.recoveryCodesRemaining', null));

        // The unconfirmed secret does not gate sign-in yet.
        expect($this->auditTrail($this->acme, 'auth.two_factor_enabled'))->toHaveCount(0);

        // 2. Confirm: a wrong code changes nothing.
        $this->post('http://acme.localhost/settings/two-factor/confirm', ['code' => $this->wrongTwoFactorCode($this->member)])
            ->assertSessionHasErrors('code');

        expect($this->member->refresh()->hasEnabledTwoFactor())->toBeFalse();

        $this->post('http://acme.localhost/settings/two-factor/confirm', ['code' => $this->twoFactorCode($this->member)])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            // 3. The recovery codes are shown once, right now.
            ->assertInertiaFlash('recoveryCodes', $this->member->refresh()->recoveryCodes())
            ->assertInertiaFlash('toast.type', 'success');

        expect($this->member->hasEnabledTwoFactor())->toBeTrue()
            ->and($this->auditTrail($this->acme, 'auth.two_factor_enabled')->sole()->actor_id)->toBe($this->member->id);

        // Enrolment material is no longer sent to the browser.
        session()->forget(SessionKey::FLASH_DATA);

        $this->get('http://acme.localhost/settings/security')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('twoFactor.enabled', true)
                ->where('twoFactor.pending', false)
                ->where('twoFactor.secret', null)
                ->where('twoFactor.qrCode', null)
                ->where('twoFactor.recoveryCodesRemaining', 8)
                ->where('auth.user.twoFactorEnabled', true));

        // Starting again while it is on does not silently replace the secret.
        $secret = $this->member->two_factor_secret;
        $this->post('http://acme.localhost/settings/two-factor', ['current_password' => 'password']);
        expect($this->member->refresh()->two_factor_secret)->toBe($secret);

        // 4. New recovery codes: password again, old ones are replaced.
        $before = $this->member->recoveryCodes();

        $this->post('http://acme.localhost/settings/two-factor/recovery-codes', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        expect($this->member->refresh()->recoveryCodes())->toBe($before);

        $this->post('http://acme.localhost/settings/two-factor/recovery-codes', ['current_password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $after = $this->member->refresh()->recoveryCodes();

        expect($after)->toHaveCount(8)
            ->and(array_intersect($before, $after))->toBe([])
            ->and(session(SessionKey::FLASH_DATA)['recoveryCodes'])->toBe($after)
            ->and($this->auditTrail($this->acme, 'auth.recovery_codes_regenerated'))->toHaveCount(1);

        // 5. Off: password again.
        $this->delete('http://acme.localhost/settings/two-factor', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        expect($this->member->refresh()->hasEnabledTwoFactor())->toBeTrue();

        $this->delete('http://acme.localhost/settings/two-factor', ['current_password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->member->refresh();

        expect($this->member->hasEnabledTwoFactor())->toBeFalse()
            ->and($this->member->two_factor_secret)->toBeNull()
            ->and($this->member->two_factor_recovery_codes)->toBeNull()
            ->and($this->member->two_factor_confirmed_at)->toBeNull()
            ->and($this->auditTrail($this->acme, 'auth.two_factor_disabled'))->toHaveCount(1);
    });

    it('lets someone back out of a setup they never finished, without recording that anything was turned off', function () {
        $this->post('http://acme.localhost/settings/two-factor', ['current_password' => 'password']);
        expect($this->member->refresh()->two_factor_secret)->not->toBeNull();

        $this->delete('http://acme.localhost/settings/two-factor', ['current_password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->member->refresh()->two_factor_secret)->toBeNull()
            ->and($this->member->recoveryCodes())->toBe([])
            ->and($this->auditTrail($this->acme, 'auth.two_factor_disabled'))->toHaveCount(0);
    });

    it('never writes the secret or the recovery codes to the audit trail', function () {
        $this->post('http://acme.localhost/settings/two-factor', ['current_password' => 'password']);
        $this->post('http://acme.localhost/settings/two-factor/confirm', ['code' => $this->twoFactorCode($this->member)]);
        $this->post('http://acme.localhost/settings/two-factor/recovery-codes', ['current_password' => 'password']);

        $this->member->refresh();
        $trail = $this->auditTrail($this->acme)->toJson();

        expect($trail)->not->toContain($this->member->two_factor_secret);

        foreach ($this->member->recoveryCodes() as $code) {
            expect($trail)->not->toContain($code);
        }
    });

    it('stores the secret and the recovery codes encrypted', function () {
        app(TwoFactorService::class)->enable($this->member);

        $raw = $this->member->refresh()->getRawOriginal();

        expect($raw['two_factor_secret'])->not->toBe($this->member->two_factor_secret)
            ->and($raw['two_factor_recovery_codes'])->not->toContain($this->member->recoveryCodes()[0])
            ->and(array_keys($this->member->toArray()))->not->toContain('two_factor_secret', 'two_factor_recovery_codes', 'password', 'remember_token');
    });

    it('cannot confirm before enrolment has started', function () {
        $this->post('http://acme.localhost/settings/two-factor/confirm', ['code' => '123456'])
            ->assertSessionHasErrors('code');

        expect($this->member->refresh()->hasEnabledTwoFactor())->toBeFalse();
    });

    it('cannot issue recovery codes while two-factor is off', function () {
        $this->post('http://acme.localhost/settings/two-factor/recovery-codes', ['current_password' => 'password'])
            ->assertStatus(409);

        expect($this->member->refresh()->two_factor_recovery_codes)->toBeNull();
    });

    it('gates the next sign-in once it is on', function () {
        $this->enableTwoFactor($this->member);
        $this->startNewBrowserSession();

        $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password'])
            ->assertRedirect('http://acme.localhost/two-factor-challenge');

        $this->assertGuest('web');
    });
});

describe('the profile', function () {
    it('shows the person\'s own details', function () {
        $this->get('http://acme.localhost/settings/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Settings/Profile')
                ->where('profile.name', $this->member->name)
                ->where('profile.email', $this->member->email)
                ->where('profile.locale', 'en')
                ->where('profile.numerals', 'latn'));
    });

    it('saves name, language and numerals', function () {
        $this->put('http://acme.localhost/settings/profile', ['name' => 'রহিম উদ্দিন', 'locale' => 'bn', 'numerals' => 'beng'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertCookie('locale', 'bn');

        $this->member->refresh();

        expect($this->member->name)->toBe('রহিম উদ্দিন')
            ->and($this->member->locale)->toBe('bn')
            ->and($this->member->preference('numerals'))->toBe('beng');

        $this->get('http://acme.localhost/settings/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'bn')
                ->where('app.numerals', 'beng')
                ->where('auth.user.initials', 'রউ'));
    });

    it('does not let the email or anything else be changed through it', function () {
        $email = $this->member->email;

        $this->put('http://acme.localhost/settings/profile', [
            'name' => 'New Name',
            'locale' => 'en',
            'numerals' => 'latn',
            'email' => 'hijacked@example.test',
            'password' => 'hijacked-password',
            'two_factor_confirmed_at' => null,
        ])->assertSessionHasNoErrors();

        $this->member->refresh();

        expect($this->member->email)->toBe($email)
            ->and(Hash::check('password', $this->member->password))->toBeTrue();
    });

    it('validates the profile', function (array $override, string $field) {
        $this->put('http://acme.localhost/settings/profile', ['name' => 'Someone', 'locale' => 'en', 'numerals' => 'latn', ...$override])
            ->assertSessionHasErrors($field);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'name too long' => [['name' => str_repeat('a', 121)], 'name'],
        'unsupported language' => [['locale' => 'fr'], 'locale'],
        'unsupported numerals' => [['numerals' => 'roman'], 'numerals'],
    ]);
});
