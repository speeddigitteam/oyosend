<?php

declare(strict_types=1);

namespace App\Core\Identity\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A sign-in that has passed the password check and is waiting for its second
 * factor. Held in the session, per guard; the person is not authenticated
 * until the challenge succeeds.
 */
final class PendingTwoFactorLogin
{
    private const int MAX_FAILURES = 5;

    public function start(Request $request, string $guard, Authenticatable $user, bool $remember): void
    {
        $request->session()->put($this->key($guard), [
            'id' => $user->getAuthIdentifier(),
            'remember' => $remember,
            'expires_at' => Carbon::now()->addSeconds((int) config('platform.two_factor.challenge_ttl', 600))->getTimestamp(),
            'failures' => 0,
        ]);
    }

    public function exists(Request $request, string $guard): bool
    {
        return $this->state($request, $guard) !== null;
    }

    public function user(Request $request, string $guard): ?Authenticatable
    {
        $state = $this->state($request, $guard);

        if ($state === null) {
            return null;
        }

        return Auth::createUserProvider(config("auth.guards.{$guard}.provider"))?->retrieveById($state['id']);
    }

    public function remember(Request $request, string $guard): bool
    {
        return (bool) ($this->state($request, $guard)['remember'] ?? false);
    }

    /**
     * Count a wrong code. Returns false once the attempt is used up, at
     * which point the pending sign-in is discarded and must start over.
     */
    public function recordFailure(Request $request, string $guard): bool
    {
        $state = $this->state($request, $guard);

        if ($state === null) {
            return false;
        }

        $state['failures']++;

        if ($state['failures'] >= self::MAX_FAILURES) {
            $this->clear($request, $guard);

            return false;
        }

        $request->session()->put($this->key($guard), $state);

        return true;
    }

    public function clear(Request $request, string $guard): void
    {
        $request->session()->forget($this->key($guard));
    }

    /**
     * @return array{id: mixed, remember: bool, expires_at: int, failures: int}|null
     */
    private function state(Request $request, string $guard): ?array
    {
        $state = $request->session()->get($this->key($guard));

        if (! is_array($state) || ! isset($state['id'], $state['expires_at'])) {
            return null;
        }

        if ((int) $state['expires_at'] < Carbon::now()->getTimestamp()) {
            $this->clear($request, $guard);

            return null;
        }

        return [
            'id' => $state['id'],
            'remember' => (bool) ($state['remember'] ?? false),
            'expires_at' => (int) $state['expires_at'],
            'failures' => (int) ($state['failures'] ?? 0),
        ];
    }

    private function key(string $guard): string
    {
        return 'auth.pending_two_factor.'.$guard;
    }
}
