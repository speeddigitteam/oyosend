<?php

declare(strict_types=1);

namespace App\Core\Identity\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Locks out repeated failed sign-ins for an (area, email, IP) combination.
 */
final class LoginThrottle
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public static function key(string $area, string $email, ?string $ip): string
    {
        return 'login:'.$area.'|'.Str::transliterate(Str::lower($email)).'|'.$ip;
    }

    /**
     * @throws ValidationException
     */
    public function ensureNotLocked(string $key, string $field = 'email'): void
    {
        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            $field => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
        ]);
    }

    public function hit(string $key): void
    {
        RateLimiter::hit($key, self::DECAY_SECONDS);
    }

    public function clear(string $key): void
    {
        RateLimiter::clear($key);
    }
}
