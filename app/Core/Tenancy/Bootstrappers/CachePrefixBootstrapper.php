<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Gives each tenant its own cache key namespace.
 *
 * The prefix of the configured stores is rewritten for the lifetime of the
 * tenant context, so ordinary `Cache::` calls - including `Cache::store()` -
 * cannot read or overwrite another tenant's entries. Unlike a tag-based
 * approach this works on every prefix-aware store (redis, database, ...).
 *
 * The un-prefixed cache stays reachable as the `cache.central` binding.
 */
final class CachePrefixBootstrapper implements TenancyBootstrapper
{
    private ?string $originalPrefix = null;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly CacheManager $cache,
    ) {
        // Resolve the central store now, while the original prefix is active.
        $this->app->make('cache.central');
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix ??= (string) $this->config->get('cache.prefix');

        $this->applyPrefix(
            $this->originalPrefix.$this->config->get('tenancy.cache.prefix_base', 'tenant_').$tenant->getTenantKey().'_',
        );
    }

    public function revert(): void
    {
        if ($this->originalPrefix === null) {
            return;
        }

        $this->applyPrefix($this->originalPrefix);
    }

    private function applyPrefix(string $prefix): void
    {
        $this->config->set('cache.prefix', $prefix);

        /** @var list<string> $stores */
        $stores = $this->config->get('tenancy.cache.stores', []);

        foreach ($stores as $store) {
            // Some stores carry their own `prefix` key, which would win over the global one.
            if ($this->config->has("cache.stores.{$store}.prefix")) {
                $this->config->set("cache.stores.{$store}.prefix", $prefix);
            }

            $this->cache->forgetDriver($store);
        }

        // Drop singletons that captured a store built with the previous prefix.
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstance('cache.store');
        Facade::clearResolvedInstance(RateLimiter::class);
    }
}
