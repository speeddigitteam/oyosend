<?php

declare(strict_types=1);

use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Access\PermissionResolver;
use App\Core\Access\RoleManager;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * Grants are cached per tenant and user. These tests run on the shared
 * `database` store so a cached answer genuinely survives from one request to
 * the next - and to another workspace - and a missing invalidation shows.
 */
beforeEach(function () {
    $this->useDatabaseCache();

    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->auditors = $this->createRole($this->acme, 'Auditors', ['core.audit.view']);
    $this->auditor = $this->addMember($this->acme, $this->auditors);

    /** A fresh request-scoped answer, the way the next request would compute it. */
    $this->can = function (User $user, string $permission, ?Tenant $tenant = null): bool {
        $this->app->forgetScopedInstances();

        return $this->inTenant($tenant ?? $this->acme, fn (): bool => Gate::forUser($user)->allows($permission));
    };

    $this->roleQueries = function (callable $callback): int {
        $count = 0;

        DB::listen(function ($query) use (&$count): void {
            $count += (int) str_contains($query->sql, 'model_has_roles');
        });

        $callback();

        return $count;
    };
});

it('reads a person\'s grants once and then answers from the tenant\'s cache', function () {
    $queries = ($this->roleQueries)(function (): void {
        ($this->can)($this->auditor, 'core.audit.view');
        ($this->can)($this->auditor, 'core.members.view');
        ($this->can)($this->auditor, 'core.roles.manage');
    });

    expect($queries)->toBe(1);

    // The cache really is what answers: a change made behind the role manager's back is not seen ...
    DB::table('role_has_permissions')->where('role_id', $this->auditors->id)->delete();
    expect(($this->can)($this->auditor, 'core.audit.view'))->toBeTrue();

    // ... until the tenant's grants are flushed.
    $this->inTenant($this->acme, fn () => app(RoleManager::class)->flush());
    expect(($this->can)($this->auditor, 'core.audit.view'))->toBeFalse();
});

it('applies a change to a role\'s permissions on the member\'s very next request', function () {
    $this->actingAsMember($this->acme, $this->auditor)->get('http://acme.localhost/audit-log')->assertOk();

    // The owner, in another browser, takes the permission away.
    $this->startNewBrowserSession()
        ->actingAsOwner($this->acme)
        ->put("http://acme.localhost/team/roles/{$this->auditors->id}", ['name' => 'Auditors', 'permissions' => []])
        ->assertSessionHasNoErrors();

    $this->startNewBrowserSession()
        ->actingAsMember($this->acme, $this->auditor)
        ->get('http://acme.localhost/audit-log')
        ->assertForbidden();

    // And gives it back.
    $this->startNewBrowserSession()
        ->actingAsOwner($this->acme)
        ->put("http://acme.localhost/team/roles/{$this->auditors->id}", ['name' => 'Auditors', 'permissions' => ['core.audit.view']])
        ->assertSessionHasNoErrors();

    $this->startNewBrowserSession()
        ->actingAsMember($this->acme, $this->auditor)
        ->get('http://acme.localhost/audit-log')
        ->assertOk();
});

it('applies a change of role on the member\'s very next request', function () {
    $membership = $this->membershipOf($this->acme, $this->auditor);

    $this->actingAsMember($this->acme, $this->auditor)->get('http://acme.localhost/audit-log')->assertOk();
    $this->get('http://acme.localhost/team/members')->assertForbidden();

    $this->startNewBrowserSession()
        ->actingAsOwner($this->acme)
        ->patch("http://acme.localhost/team/members/{$membership->id}", ['role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id])
        ->assertSessionHasNoErrors();

    $this->startNewBrowserSession()->actingAsMember($this->acme, $this->auditor);

    $this->get('http://acme.localhost/audit-log')->assertForbidden();
    $this->get('http://acme.localhost/team/members')->assertOk();
});

it('applies the deletion of a role and a new grant through a second role straight away', function () {
    $extra = $this->createRole($this->acme, 'Extra', ['core.roles.view']);

    expect(($this->can)($this->auditor, 'core.roles.view'))->toBeFalse();

    $this->inTenant($this->acme, function () use ($extra): void {
        $this->auditor->assignRole($extra);
        app(RoleManager::class)->flush();
    });

    expect(($this->can)($this->auditor, 'core.roles.view'))->toBeTrue();

    $this->inTenant($this->acme, function () use ($extra): void {
        $this->auditor->removeRole($extra);
        app(RoleManager::class)->delete($extra);
    });

    expect(($this->can)($this->auditor, 'core.roles.view'))->toBeFalse()
        ->and(($this->can)($this->auditor, 'core.audit.view'))->toBeTrue();
});

it('keeps one person\'s cached grants in two workspaces apart', function () {
    // Admin in globex, auditor in acme - same user id, hence the same cache key in both.
    $this->addMember($this->globex, Role::ADMIN, $this->auditor);

    expect(($this->can)($this->auditor, 'core.roles.manage', $this->globex))->toBeTrue()
        ->and(($this->can)($this->auditor, 'core.roles.manage', $this->acme))->toBeFalse()
        ->and(($this->can)($this->auditor, 'core.audit.view', $this->acme))->toBeTrue()
        // Warm caches on both sides, asked again in the other order.
        ->and(($this->can)($this->auditor, 'core.roles.manage', $this->acme))->toBeFalse()
        ->and(($this->can)($this->auditor, 'core.roles.manage', $this->globex))->toBeTrue();
});

it('flushes one workspace without touching another\'s cache', function () {
    $theirAdmin = $this->addMember($this->globex, Role::ADMIN);

    ($this->can)($theirAdmin, 'core.members.view', $this->globex);

    $version = fn (Tenant $tenant): int => $this->inTenant($tenant, fn (): int => (int) Cache::get('access:version', 1));
    $before = $version($this->globex);

    $this->inTenant($this->acme, fn () => app(RoleManager::class)->flush());
    $this->inTenant($this->acme, fn () => app(RoleManager::class)->flush());

    expect($version($this->acme))->toBeGreaterThan($before)
        ->and($version($this->globex))->toBe($before);

    // Globex still answers from its cache: no new query for its admin.
    expect(($this->roleQueries)(fn () => ($this->can)($theirAdmin, 'core.members.view', $this->globex)))->toBe(0);
});

it('never reads or flushes grants without a tenant', function () {
    $resolver = app(PermissionResolver::class);

    expect(fn () => $resolver->grantsFor($this->auditor))->toThrow(MissingTenantContextException::class);
    expect(fn () => $resolver->flush())->toThrow(MissingTenantContextException::class);
    expect(fn () => app(RoleManager::class)->flush())->toThrow(MissingTenantContextException::class);
});

it('counts role grants only: a direct permission on the person is not a way in', function () {
    // The permission package can also attach a permission straight to a user.
    // Decisions here never read that table, so such a row grants nothing.
    DB::table('model_has_permissions')->insert([
        'permission_id' => Permission::query()->where('name', 'core.roles.manage')->value('id'),
        'model_type' => 'user',
        'model_id' => $this->auditor->id,
        'tenant_id' => $this->acme->id,
    ]);

    $this->inTenant($this->acme, fn () => app(RoleManager::class)->flush());

    expect(($this->can)($this->auditor, 'core.roles.manage'))->toBeFalse()
        ->and(($this->can)($this->auditor, 'core.audit.view'))->toBeTrue();
});
