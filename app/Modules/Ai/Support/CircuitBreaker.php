<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * Stops hammering a failing provider. The state is platform-wide (one provider, one credential), so it
 * lives in the central cache, never in a workspace-prefixed one.
 *
 * The chat provider and the transcription provider can be different services, so each has its own breaker
 * ({@see self::transcription()}): a failing speech server does not switch off the assistant, or the reverse.
 */
final class CircuitBreaker
{
    public function __construct(private readonly string $name = 'ai') {}

    public static function transcription(): self
    {
        return new self('ai:transcription');
    }

    public function isOpen(): bool
    {
        return $this->cache()->has($this->name.':breaker:open');
    }

    public function recordFailure(): void
    {
        $cache = $this->cache();
        $window = (int) config('ai.circuit_breaker.window', 120);
        $cache->add($this->name.':breaker:failures', 0, $window);
        $failures = (int) $cache->increment($this->name.':breaker:failures');

        if ($failures >= (int) config('ai.circuit_breaker.failures', 5)) {
            $cache->put($this->name.':breaker:open', true, (int) config('ai.circuit_breaker.open', 60));
            $cache->forget($this->name.':breaker:failures');
        }
    }

    public function recordSuccess(): void
    {
        $this->cache()->forget($this->name.':breaker:failures');
    }

    /** Forget the failure history: the provider behind the breaker changed. */
    public function reset(): void
    {
        $this->cache()->forget($this->name.':breaker:failures');
        $this->cache()->forget($this->name.':breaker:open');
    }

    private function cache(): Repository
    {
        /** @var Repository $cache */
        $cache = app('cache.central');

        return $cache;
    }
}
