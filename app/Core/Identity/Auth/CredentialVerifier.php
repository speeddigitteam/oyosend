<?php

declare(strict_types=1);

namespace App\Core\Identity\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use SensitiveParameter;

/**
 * Checks an email/password pair against a guard's users without signing in.
 *
 * Sign-in is two steps here (password, then possibly a second factor), so the
 * password check must not create a session on its own. Every call takes the
 * same minimum time, whether or not the account exists.
 */
final class CredentialVerifier
{
    private const int MINIMUM_MICROSECONDS = 250_000;

    public function __construct(private readonly Timebox $timebox) {}

    public function verify(string $guard, string $email, #[SensitiveParameter] string $password): ?Authenticatable
    {
        return $this->timebox->call(function () use ($guard, $email, $password): ?Authenticatable {
            $provider = Auth::createUserProvider(config("auth.guards.{$guard}.provider"));

            if ($provider === null) {
                return null;
            }

            $credentials = ['email' => $email, 'password' => $password];
            $user = $provider->retrieveByCredentials($credentials);

            if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
                return null;
            }

            $provider->rehashPasswordIfRequired($user, $credentials);

            return $user;
        }, self::MINIMUM_MICROSECONDS);
    }
}
