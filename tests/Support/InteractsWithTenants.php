<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\Models\Role;
use App\Core\Access\PermissionSynchronizer;
use App\Core\Access\RoleManager;
use App\Core\Audit\Models\AuditLog;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Bootstrappers\CachePrefixBootstrapper;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Building blocks for any test that needs a workspace: provision one, put
 * people in it, sign in as one of them, and address it by URL.
 *
 * Outside of `inTenant()` a test runs in the central context, exactly like
 * platform code. After a request to a workspace host the tenant of that
 * request stays active (nothing ends it, as in a real process) - wrap
 * assertions on tenant-owned models in `inTenant()` rather than relying on it.
 *
 * @mixin TestCase
 */
trait InteractsWithTenants
{
    private bool $permissionCatalogueSynced = false;

    /**
     * Fill the `permissions` table from the registered module manifests.
     * Provisioning a tenant needs it; call it again after registering a module.
     */
    protected function syncPermissions(): void
    {
        app(PermissionSynchronizer::class)->sync();

        $this->permissionCatalogueSynced = true;
    }

    /**
     * A fully provisioned workspace: tenant, subdomain, system roles, owner.
     *
     * @param  array<string, mixed>  $attributes  name, status, locale, timezone, currency, trial_ends_at
     */
    protected function createTenant(?string $slug = null, array $attributes = [], ?User $owner = null): Tenant
    {
        if (! $this->permissionCatalogueSynced) {
            $this->syncPermissions();
        }

        $slug ??= 'ws-'.Str::lower(Str::random(12));
        $name = (string) ($attributes['name'] ?? Str::headline($slug));
        unset($attributes['name']);

        return app(TenantProvisioner::class)->provision(
            $name,
            $slug,
            $owner ?? User::factory()->create(),
            $attributes,
        );
    }

    protected function ownerOf(Tenant $tenant): User
    {
        /** @var TenantMembership $membership */
        $membership = TenantMembership::forTenant($tenant)
            ->where('is_owner', true)
            ->with('user')
            ->firstOrFail();

        return $membership->user;
    }

    /**
     * Put a person in a workspace with one of its roles.
     *
     * @param  Role|string  $role  A role of that tenant, or the name of one (`Role::ADMIN`, a custom name, ...).
     * @param  User|null  $user  An existing account, e.g. someone who already belongs to another tenant.
     */
    protected function addMember(
        Tenant $tenant,
        Role|string $role = Role::EMPLOYEE,
        ?User $user = null,
        MembershipStatus $status = MembershipStatus::Active,
    ): User {
        $user ??= User::factory()->create();

        $this->inTenant($tenant, function () use ($tenant, $role, $user, $status): void {
            TenantMembership::query()->create([
                'user_id' => $user->getKey(),
                'status' => $status,
                'is_owner' => false,
                'joined_at' => Carbon::now(),
            ]);

            $user->assignRole($role instanceof Role ? $role : $this->roleOf($tenant, $role));

            app(RoleManager::class)->flush();
        });

        return $user;
    }

    protected function membershipOf(Tenant $tenant, User $user): TenantMembership
    {
        /** @var TenantMembership $membership */
        $membership = TenantMembership::forTenant($tenant)
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        return $membership;
    }

    protected function roleOf(Tenant $tenant, string $name): Role
    {
        /** @var Role $role */
        $role = Role::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->where('name', $name)
            ->firstOrFail();

        return $role;
    }

    /**
     * A custom (non-system) role in the tenant, created the way the product does.
     *
     * @param  list<string>  $permissions
     */
    protected function createRole(Tenant $tenant, string $name, array $permissions = []): Role
    {
        $owner = $this->ownerOf($tenant);

        return $this->inTenant(
            $tenant,
            fn (): Role => app(RoleManager::class)->create($owner, $name, null, $permissions),
        );
    }

    /**
     * An invitation to the workspace whose emailed token the test knows.
     *
     * @param  array<string, mixed>  $attributes  e.g. expires_at, revoked_at, accepted_at, invited_by
     * @return array{0: Invitation, 1: string} the invitation and its plain token
     */
    protected function createInvitation(
        Tenant $tenant,
        string $email,
        Role|string $role = Role::EMPLOYEE,
        array $attributes = [],
    ): array {
        $token = Str::random(64);

        $invitation = $this->inTenant($tenant, fn (): Invitation => Invitation::factory()->withToken($token)->create([
            'email' => Str::lower($email),
            'role_id' => ($role instanceof Role ? $role : $this->roleOf($tenant, $role))->getKey(),
            'invited_by' => $this->ownerOf($tenant)->getKey(),
            ...$attributes,
        ]));

        return [$invitation, $token];
    }

    /**
     * The audit trail of a workspace, oldest first - or, with no tenant, the
     * platform-level entries (the ones that belong to no workspace).
     *
     * @return Collection<int, AuditLog>
     */
    protected function auditTrail(?Tenant $tenant = null, ?string $event = null): Collection
    {
        return AuditLog::withoutTenancy()
            ->when(
                $tenant === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenant?->getTenantKey()),
            )
            ->when($event !== null, fn ($query) => $query->where('event', $event))
            ->orderBy('id')
            ->get();
    }

    /**
     * Sign in as a member of a workspace.
     *
     * Plain `actingAs()` is not enough: a workspace session is bound to the
     * tenant it was created in, and EnsureTenantMember rejects one that is not.
     */
    protected function actingAsMember(Tenant $tenant, User $user): static
    {
        return $this
            ->actingAs($user, 'web')
            ->withSession([EnsureTenantMember::SESSION_KEY => $tenant->getTenantKey()]);
    }

    protected function actingAsOwner(Tenant $tenant): static
    {
        return $this->actingAsMember($tenant, $this->ownerOf($tenant));
    }

    /**
     * Forget who is signed in and everything in the session, as if the next
     * request came from a different browser.
     */
    protected function startNewBrowserSession(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->app['session']->regenerate(true);

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }

    /**
     * Switch the cache to the shared, prefix-aware `database` store.
     *
     * The suite's default `array` store ignores key prefixes: tenants are only
     * kept apart there because the store is thrown away on every context
     * switch. A test about cache isolation - or about cached state that must
     * survive requests to more than one workspace (rate limits, grants) -
     * needs a store that really is shared. Call this before the first tenant
     * is created; the entries are rolled back with the test's transaction.
     */
    protected function useDatabaseCache(): void
    {
        if ($this->app->resolved(CachePrefixBootstrapper::class)) {
            throw new LogicException('useDatabaseCache() must be called before the first tenant is created or activated.');
        }

        config(['cache.default' => 'database', 'cache.limiter' => null]);

        foreach (['cache.store', 'cache.central', RateLimiter::class] as $abstract) {
            $this->app->forgetInstance($abstract);
            Facade::clearResolvedInstance($abstract);
        }
    }

    /**
     * Absolute URL on a workspace host: `http://{slug}.localhost/{path}`.
     */
    protected function tenantUrl(Tenant|string $tenant, string $path = '/'): string
    {
        return app(TenantHost::class)->url($tenant, $path);
    }

    /**
     * Absolute URL on the central site: `http://localhost/{path}`.
     */
    protected function centralUrl(string $path = '/'): string
    {
        return $this->urlOnHost(app(TenantHost::class)->domain(), $path);
    }

    /**
     * Absolute URL on the Super Admin panel: `http://admin.localhost/{path}`.
     */
    protected function adminUrl(string $path = '/'): string
    {
        return $this->urlOnHost(app(TenantHost::class)->adminHost(), $path);
    }

    /**
     * Run a callback with the tenant active, then restore the previous context.
     *
     * @template T
     *
     * @param  Closure(Tenant): T  $callback
     * @return T
     */
    protected function inTenant(Tenant $tenant, Closure $callback): mixed
    {
        return app(TenantContext::class)->run($tenant, $callback);
    }

    /**
     * Run a callback with no tenant active, then restore the previous context.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function inCentral(Closure $callback): mixed
    {
        return app(TenantContext::class)->central($callback);
    }

    private function urlOnHost(string $host, string $path): string
    {
        $base = parse_url((string) config('app.url'));
        $scheme = $base['scheme'] ?? 'http';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        return $scheme.'://'.$host.$port.'/'.ltrim($path, '/');
    }
}
