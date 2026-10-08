<?php

declare(strict_types=1);

use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;

it('creates a super admin who can then sign in and must enrol in two-factor', function () {
    $this->artisan('platform:create-admin', ['email' => '  Root@Oyosend.TEST '])
        ->expectsQuestion('Full name', 'Root Admin')
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->expectsOutputToContain('root@oyosend.test can now sign in at admin.localhost.')
        ->assertSuccessful();

    $admin = PlatformUser::query()->sole();

    expect($admin->email)->toBe('root@oyosend.test')
        ->and($admin->name)->toBe('Root Admin')
        ->and($admin->role)->toBe(PlatformRole::SuperAdmin)
        ->and(Hash::check('a-long-enough-password', $admin->password))->toBeTrue()
        ->and($admin->hasEnabledTwoFactor())->toBeFalse();

    // A platform-level audit entry, without the password.
    $entry = $this->auditTrail(null, 'platform.admin.created')->sole();

    expect($entry->subject_id)->toBe($admin->id)
        ->and($entry->subject_type)->toBe('platform_user')
        ->and($entry->properties)->toEqual(['role' => 'super_admin', 'via' => 'console'])
        ->and($entry->toJson())->not->toContain('a-long-enough-password');

    $this->post('http://admin.localhost/login', ['email' => 'root@oyosend.test', 'password' => 'a-long-enough-password'])
        ->assertRedirect('http://admin.localhost');
    $this->get('http://admin.localhost/')->assertRedirect('http://admin.localhost/two-factor/setup');
});

it('takes the name and the role as options', function () {
    $this->artisan('platform:create-admin', ['email' => 'help@oyosend.test', '--name' => 'Help Desk', '--role' => 'support_admin'])
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->assertSuccessful();

    expect(PlatformUser::query()->sole()->role)->toBe(PlatformRole::SupportAdmin);
});

it('refuses invalid input and creates nothing', function (array $arguments, array $answers) {
    PlatformUser::factory()->create(['email' => 'taken@oyosend.test']);

    $command = $this->artisan('platform:create-admin', $arguments);

    foreach ($answers as $question => $answer) {
        $command->expectsQuestion($question, $answer);
    }

    $command->assertFailed();

    expect(PlatformUser::query()->count())->toBe(1)
        ->and($this->auditTrail(null, 'platform.admin.created'))->toHaveCount(0);
})->with([
    'an address already in use' => [
        ['email' => 'taken@oyosend.test', '--name' => 'Someone'],
        ['Password' => 'a-long-enough-password', 'Confirm password' => 'a-long-enough-password'],
    ],
    'not an email address' => [
        ['email' => 'not-an-email', '--name' => 'Someone'],
        ['Password' => 'a-long-enough-password', 'Confirm password' => 'a-long-enough-password'],
    ],
    'an unknown role' => [
        ['email' => 'new@oyosend.test', '--name' => 'Someone', '--role' => 'owner'],
        ['Password' => 'a-long-enough-password', 'Confirm password' => 'a-long-enough-password'],
    ],
    'a password that is too short' => [
        ['email' => 'new@oyosend.test', '--name' => 'Someone'],
        ['Password' => 'short', 'Confirm password' => 'short'],
    ],
    'a confirmation that does not match' => [
        ['email' => 'new@oyosend.test', '--name' => 'Someone'],
        ['Password' => 'a-long-enough-password', 'Confirm password' => 'something-else-entirely'],
    ],
    'no name' => [
        ['email' => 'new@oyosend.test'],
        ['Full name' => '', 'Password' => 'a-long-enough-password', 'Confirm password' => 'a-long-enough-password'],
    ],
]);

it('never takes the password from the command line', function () {
    $this->artisan('platform:create-admin', ['email' => 'new@oyosend.test', '--name' => 'Someone', '--no-interaction' => true])
        ->expectsOutputToContain('the password is typed in, never passed on the command line')
        ->assertFailed();

    expect(PlatformUser::query()->count())->toBe(0)
        ->and(Artisan::all()['platform:create-admin']->getDefinition()->hasOption('password'))->toBeFalse();
});
