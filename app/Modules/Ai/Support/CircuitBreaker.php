<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * Stops hammering a failing provider. The state is platform-wide (one provider, one credential), so it
 * lives in the central cache, never in a workspace-prefixed one.
 */
final class CircuitBreaker
{
    private const string FAILURES = 'ai:breaker:failures';

    private const string OPEN = 'ai:breaker:open';

    public function isOpen(): bool
    {
        return $this->cache()->has(self::OPEN);
    }

    public function recordFailure(): void
    {
        $cache = $this->cache();
        $window = (int) config('ai.circuit_breaker.window', 120);
        $cache->add(self::FAILURES, 0, $window);
        $failures = (int) $cache->increment(self::FAILURES);

        if ($failures >= (int) config('ai.circuit_breaker.failures', 5)) {
            $cache->put(self::OPEN, true, (int) config('ai.circuit_breaker.open', 60));
            $cache->forget(self::FAILURES);
        }
    }

    public function recordSuccess(): void
    {
        $this->cache()->forget(self::FAILURES);
    }

    private function cache(): Repository
    {
        /** @var Repository $cache */
        $cache = app('cache.central');

        return $cache;
    }
}
