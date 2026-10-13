<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Enums\ObjectLevel;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Models\Insight;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Building blocks for Meta Ads tests: a workspace entitled to the app (and Social, which it depends on), a
 * configured Meta app, connections, ad accounts, synced objects and insight rows, and role helpers.
 *
 * @mixin TestCase
 */
trait InteractsWithMetaAds
{
    protected function metaTenant(string $slug = 'meta-ws'): Tenant
    {
        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);
        $this->entitle($tenant, 'social', 'meta_ads');
        $this->configureMeta();

        return $tenant;
    }

    protected function configureMeta(bool $management = true): void
    {
        config([
            'meta_ads.app_id' => 'app-1234',
            'meta_ads.app_secret' => 'app-secret-xyz',
            'meta_ads.api_version' => 'v23.0',
            'meta_ads.request_management_scope' => $management,
            'meta_ads.redirect_broker' => true,
        ]);
    }

    protected function metaUrl(Tenant $tenant, string $path = ''): string
    {
        return $this->tenantUrl($tenant, '/meta-ads'.$path);
    }

    /** @param array<string, mixed> $overrides */
    protected function metaConnection(Tenant $tenant, array $overrides = []): Connection
    {
        return $this->inTenant($tenant, fn (): Connection => Connection::query()->create([
            'external_user_id' => 'fb-'.bin2hex(random_bytes(4)),
            'user_name' => 'Rahim Owner',
            'access_token' => 'EAAB-token-'.bin2hex(random_bytes(6)),
            'token_expires_at' => now()->addDays(55),
            'granted_scopes' => ['ads_read', 'ads_management'],
            'status' => ConnectionStatus::Connected,
            'connected_at' => now(),
            ...$overrides,
        ]));
    }

    /** @param array<string, mixed> $overrides */
    protected function metaAccount(Tenant $tenant, ?Connection $connection = null, array $overrides = []): AdAccount
    {
        $connection ??= $this->metaConnection($tenant);

        return $this->inTenant($tenant, fn (): AdAccount => AdAccount::query()->create([
            'connection_id' => $connection->id,
            'external_id' => 'act_'.random_int(100000, 999999),
            'name' => 'Shop Ads',
            'currency' => 'BDT',
            'timezone_name' => 'Asia/Dhaka',
            'meta_status' => 1,
            'status' => AdAccountStatus::Tracked,
            ...$overrides,
        ]));
    }

    /** @param array<string, mixed> $overrides */
    protected function metaObject(Tenant $tenant, AdAccount $account, array $overrides = []): AdObject
    {
        return $this->inTenant($tenant, fn (): AdObject => AdObject::query()->create([
            'account_id' => $account->id,
            'level' => ObjectLevel::Campaign,
            'external_id' => (string) random_int(1000000, 9999999),
            'name' => 'Eid Sale',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'objective' => 'OUTCOME_LEADS',
            'daily_budget' => 100000,
            'currency' => $account->currency,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            ...$overrides,
        ]));
    }

    /** @param array<string, mixed> $overrides */
    protected function metaInsight(Tenant $tenant, AdAccount $account, string $date, array $overrides = []): Insight
    {
        return $this->inTenant($tenant, fn (): Insight => Insight::query()->create([
            'account_id' => $account->id,
            'level' => Insight::LEVEL_ACCOUNT,
            'object_external_id' => $account->external_id,
            'metric_date' => $date,
            'currency' => $account->currency,
            'spend' => 50000,
            'impressions' => 10000,
            'reach' => 8000,
            'clicks' => 200,
            'leads' => 10,
            'synced_at' => now(),
            ...$overrides,
        ]));
    }

    /**
     * A member with a custom role.
     *
     * @param  list<string>  $permissions
     * @return array{0: User, 1: TenantMembership}
     */
    protected function metaMember(Tenant $tenant, array $permissions): array
    {
        $owner = $this->ownerOf($tenant);
        $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
            $owner,
            'Role '.Str::lower(bin2hex(random_bytes(4))),
            null,
            $permissions,
            [],
        ));
        $user = $this->addMember($tenant, $role);

        return [$user, $this->membershipOf($tenant, $user)];
    }

    /**
     * Fake Meta's Graph API. Keys are URL patterns (`*` wildcards); a value is an array body or a closure
     * returning a response. Anything not listed answers 500 so a test never silently depends on the network.
     *
     * @param  array<string, mixed>  $routes
     */
    protected function fakeMeta(array $routes): void
    {
        $fake = [];

        foreach ($routes as $pattern => $response) {
            $fake['graph.facebook.com/v23.0/'.$pattern] = is_array($response) ? Http::response($response) : $response;
        }

        $fake['*'] = Http::response(['error' => ['message' => 'unexpected call', 'code' => 1]], 500);

        // A fresh client each time: stubs registered by an earlier call must not win over these.
        $factory = new Factory;
        Http::swap($factory);
        $this->app->instance(Factory::class, $factory);
        Http::fake($fake);
    }

    /** @param array<string, mixed> $row */
    protected function graphError(int $code, string $message = 'boom', int $status = 400, array $row = []): mixed
    {
        return Http::response(['error' => ['message' => $message, 'code' => $code, ...$row]], $status);
    }
}
