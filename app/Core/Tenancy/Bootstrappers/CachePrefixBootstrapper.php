<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Bootstrappers;

use App\Core\Tenancy\Support\TenantAwareRateLimiter;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Gives each tenant its own cache key namespace.
 *
 * The prefix of every configured store is rewritten for the lifetime of the
 * tenant context, so ordinary `Cache::` calls - including `Cache::store()` -
 * cannot read or overwrite another tenant's entries. Unlike a tag-based
 * approach this works on every prefix-aware store (redis, database, ...).
 * A store that ignores prefixes (array) is simply rebuilt empty.
 *
 * The un-prefixed cache stays reachable as the `cache.central` binding.
 */
final class CachePrefixBootstrapper implements TenancyBootstrapper
{
    /**
     * The prefixes of the central context, by config key: the global
     * `cache.prefix`, plus the own prefix of every store that defines one.
     *
     * @var array<string, string>|null
     */
    private ?array $centralPrefixes = null;

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
        $this->centralPrefixes ??= $this->currentPrefixes();

        $suffix = $this->config->get('tenancy.cache.prefix_base', 'tenant_').$tenant->getTenantKey().'_';

        $this->applyPrefixes(array_map(
            static fn (string $prefix): string => $prefix.$suffix,
            $this->centralPrefixes,
        ));
    }

    public function revert(): void
    {
        if ($this->centralPrefixes === null) {
            return;
        }

        $this->applyPrefixes($this->centralPrefixes);
    }

    /**
     * @return array<string, string> config key => prefix
     */
    private function currentPrefixes(): array
    {
        $prefixes = ['cache.prefix' => (string) $this->config->get('cache.prefix')];

        foreach ($this->stores() as $store) {
            // A store's own `prefix` wins over the global one, so it needs the same treatment.
            $own = $this->config->get("cache.stores.{$store}.prefix");

            if (is_string($own)) {
                $prefixes["cache.stores.{$store}.prefix"] = $own;
            }
        }

        return $prefixes;
    }

    /**
     * @param  array<string, string>  $prefixes  config key => prefix
     */
    private function applyPrefixes(array $prefixes): void
    {
        $this->config->set($prefixes);

        // Every store, not just the default one: a store keeps the prefix it
        // was built with, so one left alone here would hand the entries of the
        // first tenant that used it to every tenant after.
        $this->cache->forgetDriver($this->stores());

        // `Cache::memo()` keeps, per store and for the rest of the request, a
        // wrapper around the store it was first asked for and every value it
        // has read through it. Both belong to the previous context.
        foreach ($this->stores() as $store) {
            $this->app->forgetInstance('cache.__memoized:'.$store);
        }

        // Drop the singleton that captured a store built with the previous prefix.
        $this->app->forgetInstance('cache.store');
        Facade::clearResolvedInstance('cache.store');

        // The rate limiter captured one too, but it also holds every limiter
        // registered with RateLimiter::for(): it is given the new store rather
        // than rebuilt.
        if ($this->app->resolved(TenantAwareRateLimiter::class)) {
            $this->app->make(TenantAwareRateLimiter::class)->useStore(
                $this->cache->driver($this->config->get('cache.limiter')),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function stores(): array
    {
        /** @var array<string, mixed> $stores */
        $stores = $this->config->get('cache.stores', []);

        return array_keys($stores);
    }
}
