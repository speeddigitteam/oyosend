<?php

declare(strict_types=1);

use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Access\PermissionSynchronizer;
use App\Core\Access\RoleManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

const DECLARED_BY_CORE = [
    'core.audit.view',
    'core.members.invite',
    'core.members.manage',
    'core.members.view',
    'core.roles.manage',
    'core.roles.view',
    'core.settings.manage',
];

beforeEach(function () {
    $this->synchronizer = app(PermissionSynchronizer::class);
    $this->names = fn (): array => Permission::query()->orderBy('name')->pluck('name')->all();
});

it('creates the permissions the registered modules declare', function () {
    expect(Permission::query()->count())->toBe(0);

    $result = $this->synchronizer->sync();

    sort($result['created']);

    expect($result['created'])->toBe(DECLARED_BY_CORE)
        ->and($result['stale'])->toBe([])
        ->and($result['pruned'])->toBe([])
        ->and(($this->names)())->toBe(DECLARED_BY_CORE)
        ->and(Permission::query()->where('guard_name', 'web')->where('module', 'core')->count())->toBe(7);
});

it('is idempotent', function () {
    $this->synchronizer->sync();
    $ids = Permission::query()->orderBy('name')->pluck('id', 'name')->all();

    $again = $this->synchronizer->sync();

    expect($again)->toBe(['created' => [], 'stale' => [], 'pruned' => []])
        // Same rows, same ids: grants that point at them stay valid.
        ->and(Permission::query()->orderBy('name')->pluck('id', 'name')->all())->toBe($ids);
});

it('picks up the permissions of a module added later', function () {
    $this->synchronizer->sync();

    $this->app->register(CrmServiceProvider::class);

    $result = $this->synchronizer->sync();
    sort($result['created']);

    expect($result['created'])->toBe(['crm.leads.edit', 'crm.leads.view'])
        ->and(Permission::query()->where('module', 'crm')->count())->toBe(2)
        ->and(Permission::query()->where('module', 'core')->count())->toBe(7);
});

it('corrects the module a permission is filed under', function () {
    $this->synchronizer->sync();
    Permission::query()->where('name', 'core.audit.view')->update(['module' => 'somewhere-else']);

    $this->synchronizer->sync();

    expect(Permission::query()->where('name', 'core.audit.view')->value('module'))->toBe('core');
});

describe('permissions no module declares any more', function () {
    beforeEach(function () {
        $this->synchronizer->sync();

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');

        // A permission of a module that has since been removed, still granted in two tenants.
        $this->legacy = Permission::query()->create(['name' => 'legacy.reports.view', 'guard_name' => 'web', 'module' => 'legacy']);

        foreach ([$this->acme, $this->globex] as $tenant) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => $this->legacy->id,
                'role_id' => $this->roleOf($tenant, Role::MANAGER)->id,
                'scope' => 'all',
            ]);
        }
    });

    it('are reported as stale and left alone by default', function () {
        $result = $this->synchronizer->sync();

        expect($result)->toBe(['created' => [], 'stale' => ['legacy.reports.view'], 'pruned' => []])
            ->and(Permission::query()->whereKey($this->legacy->id)->exists())->toBeTrue()
            ->and(DB::table('role_has_permissions')->where('permission_id', $this->legacy->id)->count())->toBe(2);
    });

    it('are removed with every grant of them, in every tenant, when pruning is asked for', function () {
        $result = $this->synchronizer->sync(prune: true);

        expect($result)->toBe(['created' => [], 'stale' => ['legacy.reports.view'], 'pruned' => ['legacy.reports.view']])
            ->and(Permission::query()->whereKey($this->legacy->id)->exists())->toBeFalse()
            ->and(DB::table('role_has_permissions')->where('permission_id', $this->legacy->id)->count())->toBe(0)
            // Everything still declared survives, grants included.
            ->and(($this->names)())->toBe(DECLARED_BY_CORE)
            ->and(app(RoleManager::class)->grantedNames($this->roleOf($this->acme, Role::MANAGER)))
            ->toBe(['core.members.invite', 'core.members.view', 'core.roles.view']);
    });

    it('never touch permissions of another guard', function () {
        $foreign = Permission::query()->create(['name' => 'api.tokens.issue', 'guard_name' => 'api', 'module' => 'api']);

        $result = $this->synchronizer->sync(prune: true);

        expect($result['stale'])->toBe(['legacy.reports.view'])
            ->and(Permission::query()->whereKey($foreign->id)->exists())->toBeTrue();
    });
});

describe('php artisan permissions:sync', function () {
    it('reports what it added', function () {
        $this->artisan('permissions:sync')
            ->expectsOutputToContain('7 permission(s) added.')
            ->expectsOutputToContain('core.members.view')
            ->assertSuccessful();

        expect(($this->names)())->toBe(DECLARED_BY_CORE);

        $this->artisan('permissions:sync')->expectsOutputToContain('0 permission(s) added.')->assertSuccessful();
    });

    it('warns about stale permissions and removes them only with --prune', function () {
        $this->synchronizer->sync();
        Permission::query()->create(['name' => 'legacy.reports.view', 'guard_name' => 'web', 'module' => 'legacy']);

        $this->artisan('permissions:sync')
            ->expectsOutputToContain('1 permission(s) are no longer declared by any module. Re-run with --prune to remove them.')
            ->expectsOutputToContain('legacy.reports.view')
            ->assertSuccessful();

        expect(Permission::query()->where('name', 'legacy.reports.view')->exists())->toBeTrue();

        $this->artisan('permissions:sync', ['--prune' => true])
            ->expectsOutputToContain('legacy.reports.view')
            ->doesntExpectOutputToContain('Re-run with --prune')
            ->assertSuccessful();

        expect(($this->names)())->toBe(DECLARED_BY_CORE);
    });
});
