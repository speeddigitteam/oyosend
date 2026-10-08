<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;

/*
 * Proven on the `database` store: it is shared between tenants and honours
 * key prefixes, like Redis in production. (The `array` store used by the rest
 * of the suite cannot leak in the first place.)
 */
describe('on a shared, prefix-aware store', function () {
    beforeEach(function () {
        $this->useDatabaseCache();

        // Two more stores on the same table: a plain one, and one with a prefix of its own.
        config([
            'cache.stores.secondary' => ['driver' => 'database', 'table' => 'cache'],
            'cache.stores.branded' => ['driver' => 'database', 'table' => 'cache', 'prefix' => 'branded-'],
        ]);

        $this->centralPrefix = (string) config('cache.prefix');
        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');
    });

    it('keeps the cache of two tenants and of the central context apart', function () {
        Cache::put('greeting', 'central');

        $this->inTenant($this->acme, function (): void {
            expect(Cache::get('greeting'))->toBeNull();

            Cache::put('greeting', 'acme');
        });

        $this->inTenant($this->globex, function (): void {
            expect(Cache::get('greeting'))->toBeNull()
                ->and(Cache::has('greeting'))->toBeFalse();

            Cache::put('greeting', 'globex');
            Cache::forever('only-globex', true);
        });

        expect(Cache::get('greeting'))->toBe('central')
            ->and(Cache::get('only-globex'))->toBeNull()
            ->and($this->inTenant($this->acme, fn () => Cache::get('greeting')))->toBe('acme')
            ->and($this->inTenant($this->acme, fn () => Cache::get('only-globex')))->toBeNull()
            ->and($this->inTenant($this->globex, fn () => Cache::get('greeting')))->toBe('globex');

        // Three physical entries, one per namespace.
        expect(DB::table('cache')->where('key', 'like', '%greeting')->pluck('key')->sort()->values()->all())->toBe([
            $this->centralPrefix.'greeting',
            $this->centralPrefix.'tenant_'.$this->acme->id.'_greeting',
            $this->centralPrefix.'tenant_'.$this->globex->id.'_greeting',
        ]);
    });

    it('does not let one tenant overwrite or delete another tenant\'s entries', function () {
        $this->inTenant($this->acme, fn () => Cache::put('report', 'acme numbers'));

        $this->inTenant($this->globex, function (): void {
            expect(Cache::pull('report'))->toBeNull()
                // `add` only writes when the key is free: it is, in this tenant.
                ->and(Cache::add('report', 'globex numbers'))->toBeTrue();

            Cache::forget('report');
        });

        expect($this->inTenant($this->acme, fn () => Cache::get('report')))->toBe('acme numbers')
            ->and($this->inTenant($this->globex, fn () => Cache::get('report')))->toBeNull();
    });

    it('isolates every way of reaching the cache, including ones resolved before the tenant started', function () {
        // Resolved in the central context, the way a long-lived singleton would hold them.
        $early = [
            'facade' => fn () => Cache::get('probe'),
            'helper' => fn () => cache('probe'),
            'named store' => fn () => Cache::store('database')->get('probe'),
            'default store' => fn () => Cache::store()->get('probe'),
            'cache.store binding' => fn () => app('cache.store')->get('probe'),
            'repository contract' => fn () => app(Repository::class)->get('probe'),
            'factory contract' => fn () => app(CacheFactory::class)->store()->get('probe'),
        ];

        foreach ($early as $read) {
            $read();
        }

        $this->inTenant($this->acme, fn () => Cache::put('probe', 'acme'));

        foreach ($early as $name => $read) {
            expect($read())->toBeNull("[{$name}] read a tenant entry from the central context");
            expect($this->inTenant($this->acme, $read))->toBe('acme', "[{$name}] missed its own tenant's entry");
            expect($this->inTenant($this->globex, $read))->toBeNull("[{$name}] read another tenant's entry");
        }
    });

    it('isolates the memoized cache, which remembers both a store and its values for the whole request', function () {
        // Read once centrally, so a memoized "miss" is on record before any tenant starts.
        expect(Cache::memo()->get('probe'))->toBeNull();

        $this->inTenant($this->acme, function (): void {
            Cache::memo()->put('probe', 'acme');

            expect(Cache::memo()->get('probe'))->toBe('acme');
        });

        expect($this->inTenant($this->globex, fn () => Cache::memo()->get('probe')))->toBeNull()
            ->and(Cache::memo()->get('probe'))->toBeNull()
            ->and($this->inTenant($this->acme, fn () => Cache::memo()->get('probe')))->toBe('acme')
            ->and($this->inTenant($this->acme, fn () => Cache::memo('secondary')->get('probe')))->toBe('acme')
            ->and($this->inTenant($this->globex, fn () => Cache::memo('secondary')->get('probe')))->toBeNull();
    });

    it('isolates stores other than the default one', function () {
        // First used by acme: its prefix must not stick to the store.
        $this->inTenant($this->acme, fn () => Cache::store('secondary')->put('probe', 'acme'));

        expect($this->inTenant($this->globex, fn () => Cache::store('secondary')->get('probe')))->toBeNull()
            ->and(Cache::store('secondary')->get('probe'))->toBeNull()
            ->and($this->inTenant($this->acme, fn () => Cache::store('secondary')->get('probe')))->toBe('acme');
    });

    it('keeps the own prefix of a store that defines one', function () {
        Cache::store('branded')->put('probe', 'central');
        $this->inTenant($this->acme, fn () => Cache::store('branded')->put('probe', 'acme'));

        expect(DB::table('cache')->where('key', 'like', 'branded-%')->pluck('key')->sort()->values()->all())->toBe([
            'branded-probe',
            'branded-tenant_'.$this->acme->id.'_probe',
        ]);

        // Back in the central context the store has its original prefix again.
        expect(config('cache.stores.branded.prefix'))->toBe('branded-')
            ->and(Cache::store('branded')->get('probe'))->toBe('central')
            ->and($this->inTenant($this->globex, fn () => Cache::store('branded')->get('probe')))->toBeNull();
    });

    it('restores the central prefix when the tenant context ends', function () {
        $inside = $this->inTenant($this->acme, fn (): string => (string) config('cache.prefix'));

        expect($inside)->toBe($this->centralPrefix.'tenant_'.$this->acme->id.'_')
            ->and(config('cache.prefix'))->toBe($this->centralPrefix);

        // Switching tenant directly never stacks one tenant's prefix on another's.
        $nested = $this->inTenant($this->acme, fn (): string => $this->inTenant(
            $this->globex,
            fn (): string => (string) config('cache.prefix'),
        ));

        expect($nested)->toBe($this->centralPrefix.'tenant_'.$this->globex->id.'_');
    });

    it('keeps an un-prefixed store reachable for platform-level data', function () {
        Cache::put('platform-setting', 'on');

        $central = fn () => app('cache.central')->get('platform-setting');

        expect($central())->toBe('on')
            ->and($this->inTenant($this->acme, $central))->toBe('on')
            ->and($this->inTenant($this->acme, fn () => Cache::get('platform-setting')))->toBeNull();

        // Written from inside a tenant, it is still platform-wide.
        $this->inTenant($this->acme, fn () => app('cache.central')->put('handoff', 'token'));

        expect($this->inTenant($this->globex, fn () => app('cache.central')->get('handoff')))->toBe('token')
            ->and(Cache::get('handoff'))->toBe('token');
    });

    it('counts rate limits per tenant', function () {
        // Resolved centrally first: the limiter must not keep that store.
        RateLimiterFacade::hit('probe', 60);
        expect(app(RateLimiter::class)->attempts('probe'))->toBe(1);

        $attempts = fn (): int => (int) RateLimiterFacade::attempts('probe');

        $this->inTenant($this->acme, function () use ($attempts): void {
            expect($attempts())->toBe(0);

            RateLimiterFacade::hit('probe', 60);
            RateLimiterFacade::hit('probe', 60);
            app(RateLimiter::class)->hit('probe', 60);
        });

        expect($this->inTenant($this->globex, $attempts))->toBe(0)
            ->and($this->inTenant($this->acme, $attempts))->toBe(3)
            ->and($attempts())->toBe(1);
    });

    it('keeps named limiters when the tenant changes', function () {
        // Registered once at boot, the way a `throttle:exports` route expects to find it.
        RateLimiterFacade::for('exports', fn () => Limit::perMinute(2));

        foreach ([$this->acme, $this->globex] as $tenant) {
            $this->inTenant($tenant, function (): void {
                expect(RateLimiterFacade::limiter('exports'))->toBeInstanceOf(Closure::class);
            });
        }

        expect(RateLimiterFacade::limiter('exports'))->toBeInstanceOf(Closure::class);
    });

    it('throttles a route per workspace, not across workspaces', function () {
        $send = fn (Tenant $tenant) => $this->post($this->tenantUrl($tenant, 'forgot-password'), ['email' => 'nobody@example.test']);

        // forgot-password allows 6 requests a minute.
        foreach (range(1, 6) as $attempt) {
            $send($this->acme)->assertRedirect();
        }

        $send($this->acme)->assertTooManyRequests();

        // Same client, same route, other workspace: its own allowance.
        $send($this->globex)->assertRedirect();

        // And the first workspace is still locked - its counter was not lost by the switch.
        $send($this->acme)->assertTooManyRequests();
    });
});

it('does not carry entries between tenants on the array store either', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');

    expect(config('cache.default'))->toBe('array');

    $this->inTenant($acme, fn () => Cache::put('probe', 'acme'));

    expect($this->inTenant($globex, fn () => Cache::get('probe')))->toBeNull()
        ->and(Cache::get('probe'))->toBeNull();
});
