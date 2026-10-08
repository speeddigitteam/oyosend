<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;

/**
 * A rate limiter that can follow the tenant.
 *
 * The framework's limiter keeps the cache store it was built with, and that
 * store keeps its key prefix. Rebuilding the limiter on every tenant switch
 * would give it the right store but lose every limiter registered with
 * `RateLimiter::for()`, so the first `throttle:{name}` afterwards would fail.
 * This one keeps its named limiters and is handed the new store instead.
 */
final class TenantAwareRateLimiter extends RateLimiter
{
    public function useStore(Repository $cache): void
    {
        $this->cache = $cache;
    }
}
