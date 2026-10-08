<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->member = $this->addMember($this->acme, Role::EMPLOYEE);

    $this->requestLink = fn (string $email, string $host = 'acme'): TestResponse => $this
        ->from("http://{$host}.localhost/forgot-password")
        ->post("http://{$host}.localhost/forgot-password", ['email' => $email]);

    $this->tokenFor = fn (User $user): string => $this->inTenant(
        $this->acme,
        fn (): string => Password::broker('users')->createToken($user),
    );
});

it('shows the forgot-password and reset pages', function () {
    $this->get('http://acme.localhost/forgot-password')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Core/Pages/Auth/ForgotPassword'));

    $this->get('http://acme.localhost/reset-password/some-token?email=me@example.test')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Auth/ResetPassword')
            ->where('token', 'some-token')
            ->where('email', 'me@example.test'));
});

it('emails a member a link back to the workspace they asked from', function () {
    Notification::fake();

    ($this->requestLink)($this->member->email)->assertRedirect('http://acme.localhost/forgot-password');

    Notification::assertSentTo($this->member, ResetPassword::class, function (ResetPassword $notification): bool {
        $url = $this->inTenant($this->acme, fn (): string => $notification->toMail($this->member)->actionUrl);

        expect($url)->toStartWith('http://acme.localhost/reset-password/'.$notification->token)
            ->toContain('email='.urlencode($this->member->email));

        return true;
    });

    // Only a hash of the token is stored.
    $stored = DB::table('password_reset_tokens')->where('email', $this->member->email)->sole();

    Notification::assertSentTo($this->member, ResetPassword::class, fn (ResetPassword $notification): bool => $stored->token !== $notification->token
        && Hash::check($notification->token, $stored->token));
});

it('sends nothing to people who are not active members here, with the same answer', function () {
    Notification::fake();

    $outsider = $this->addMember($this->globex, Role::ADMIN);
    $suspended = $this->addMember($this->acme, Role::EMPLOYEE, status: MembershipStatus::Suspended);

    $answers = [];

    foreach ([$this->member->email, $outsider->email, $suspended->email, 'nobody@example.test'] as $email) {
        $response = ($this->requestLink)($email);

        $response->assertRedirect('http://acme.localhost/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $answers[] = [$response->getStatusCode(), $response->headers->get('Location'), session(SessionKey::FLASH_DATA)];

        $this->startNewBrowserSession();
    }

    // The form cannot be used to find out who has an account or belongs here.
    expect(array_unique(array_map('serialize', $answers)))->toHaveCount(1);

    Notification::assertSentTo($this->member, ResetPassword::class);
    Notification::assertNotSentTo($outsider, ResetPassword::class);
    Notification::assertNotSentTo($suspended, ResetPassword::class);
    Notification::assertCount(1);

    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe([$this->member->email]);
});

it('validates the address', function () {
    ($this->requestLink)('not-an-email')->assertSessionHasErrors('email');
    ($this->requestLink)('')->assertSessionHasErrors('email');
});

it('resets the password with a valid token', function () {
    $token = ($this->tokenFor)($this->member);
    $rememberToken = $this->member->remember_token;

    $this->post('http://acme.localhost/reset-password', [
        'token' => $token,
        'email' => $this->member->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect('http://acme.localhost/login')->assertInertiaFlash('toast.type', 'success');

    $this->member->refresh();

    expect(Hash::check('a-brand-new-password', $this->member->password))->toBeTrue()
        // Rotated, so "remember me" cookies of other devices stop working.
        ->and($this->member->remember_token)->not->toBe($rememberToken)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);

    // Resetting does not sign in by itself.
    $this->assertGuest('web');

    $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'a-brand-new-password'])
        ->assertRedirect('http://acme.localhost');

    $entry = $this->auditTrail($this->acme, 'auth.password_reset')->sole();

    expect($entry->actor_id)->toBe($this->member->id)
        ->and(json_encode($entry->properties))->not->toContain('a-brand-new-password');
});

it('rejects a wrong, reused or expired token', function () {
    $token = ($this->tokenFor)($this->member);

    $attempt = fn (string $token, ?string $email = null): TestResponse => $this->post('http://acme.localhost/reset-password', [
        'token' => $token,
        'email' => $email ?? $this->member->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $attempt('not-the-token')->assertSessionHasErrors('email');

    // Someone else's address with a real token.
    $attempt($token, $this->ownerOf($this->acme)->email)->assertSessionHasErrors('email');

    expect(Hash::check('password', $this->member->refresh()->password))->toBeTrue();

    $this->travel(61)->minutes();
    $attempt($token)->assertSessionHasErrors('email');
    $this->travelBack();

    $token = ($this->tokenFor)($this->member);
    $attempt($token)->assertRedirect('http://acme.localhost/login');
    $attempt($token)->assertSessionHasErrors('email');
});

it('validates the new password', function (array $override, string $field) {
    $this->post('http://acme.localhost/reset-password', [
        'token' => ($this->tokenFor)($this->member),
        'email' => $this->member->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
        ...$override,
    ])->assertSessionHasErrors($field);

    expect(Hash::check('password', $this->member->refresh()->password))->toBeTrue();
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'not confirmed' => [['password_confirmation' => 'something-else'], 'password'],
    'no token' => [['token' => ''], 'token'],
    'no email' => [['email' => ''], 'email'],
]);

it('keeps signed-in members away from the reset pages', function () {
    $this->actingAsMember($this->acme, $this->member)
        ->get('http://acme.localhost/forgot-password')
        ->assertRedirect('http://acme.localhost');
});

it('shows the reset page even when the link carries a malformed address', function () {
    $this->get('http://acme.localhost/reset-password/some-token?email[]=a&email[]=b')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('email', ''));
});
