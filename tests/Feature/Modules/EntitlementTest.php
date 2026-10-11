<?php

declare(strict_types=1);

use App\Core\Access\AccessChecker;
use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Automation\AutomationRegistry;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Entitlements\PlanEntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;
use Tests\Support\Fixtures\Crm\LeadCreatedTrigger;
use Tests\Support\Fixtures\Crm\PipelineWidget;

/*
 * An optional test module is added to the build. Being in the build makes
 * its code available; whether a tenant may use it is the entitlement check.
 */
beforeEach(function () {
    PipelineWidget::$invocations = 0;

    $this->registerModule(CrmServiceProvider::class);

    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->owner = $this->ownerOf($this->acme);

    /** Force a grant straight into the database, the way a lapsed licence would leave it behind. */
    $this->forceGrant = function (Role $role, string ...$permissions): void {
        foreach (Permission::query()->whereIn('name', $permissions)->pluck('id') as $id) {
            DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $role->id, 'scope' => 'all']);
        }

        $this->inTenant($this->acme, fn () => app(RoleManager::class)->flush());
    };

    $this->can = fn (User $user, string $permission, $tenant = null): bool => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): bool => Gate::forUser($user)->allows($permission),
    );
});

it('registers the module, its permissions and its trigger', function () {
    $registry = app(ModuleRegistry::class);

    expect(array_keys($registry->all()))->toBe(['core', 'crm', 'projects', 'test_crm'])
        ->and($registry->get('test_crm')->core)->toBeFalse()
        ->and($registry->findPermission('test_crm.leads.view')?->module())->toBe('test_crm')
        ->and($registry->missingDependencies())->toBe([])
        ->and(Permission::query()->where('module', 'test_crm')->pluck('name')->sort()->values()->all())->toBe(['test_crm.leads.edit', 'test_crm.leads.view'])
        ->and(app(AutomationRegistry::class)->trigger('test_crm.lead.created'))->toBe(LeadCreatedTrigger::class);
});

describe('a workspace that is not entitled', function () {
    it('denies the module\'s permissions to everyone, the owner included', function () {
        expect(app(EntitlementChecker::class))->toBeInstanceOf(PlanEntitlementChecker::class)
            ->and(app(EntitlementChecker::class)->allows($this->acme, 'test_crm'))->toBeFalse()
            ->and(app(EntitlementChecker::class)->entitledModules($this->acme))->toBe(['core']);

        expect(($this->can)($this->owner, 'test_crm.leads.view'))->toBeFalse()
            ->and(($this->can)($this->owner, 'test_crm.leads.edit'))->toBeFalse()
            // The core module is unaffected.
            ->and(($this->can)($this->owner, 'core.members.manage'))->toBeTrue();

        expect($this->inTenant(
            $this->acme,
            fn () => app(AutomationRegistry::class)->trigger('test_crm.lead.created'),
        ))->toBeNull();

        $held = $this->inTenant($this->acme, fn (): array => array_keys(app(AccessChecker::class)->effectivePermissions($this->owner)));

        expect($held)->toHaveCount(16)->each->toStartWith('core.');
    });

    it('denies a permission even when a role has been granted it', function () {
        $sales = $this->createRole($this->acme, 'Sales', ['core.members.view']);
        $rep = $this->addMember($this->acme, $sales);

        ($this->forceGrant)($sales, 'test_crm.leads.view', 'test_crm.leads.edit');

        // The grant is really there ...
        expect(app(RoleManager::class)->grantedNames($sales))->toContain('test_crm.leads.view', 'test_crm.leads.edit');

        // ... and it is worth nothing without the entitlement.
        expect(($this->can)($rep, 'test_crm.leads.view'))->toBeFalse()
            ->and(($this->can)($rep, 'test_crm.leads.edit'))->toBeFalse()
            ->and(($this->can)($rep, 'core.members.view'))->toBeTrue()
            ->and($this->inTenant($this->acme, fn (): array => array_keys(app(AccessChecker::class)->effectivePermissions($rep))))->toBe(['core.members.view']);
    });

    it('answers 404 on the module\'s routes so the app is not disclosed', function () {
        $sales = $this->createRole($this->acme, 'Sales');
        $rep = $this->addMember($this->acme, $sales);
        ($this->forceGrant)($sales, 'test_crm.leads.view');

        foreach ([$this->owner, $rep] as $user) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $user)
                ->get('http://acme.localhost/test-crm/leads')
                ->assertNotFound()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Core/Pages/Errors/Error')->where('status', 404));
        }

        // A route of the module that asks for no permission at all is gated just the same.
        $this->startNewBrowserSession()->get('http://acme.localhost/test-crm/ping')->assertNotFound();
    });

    it('does not let a role be given the module\'s permissions', function () {
        $this->inTenant($this->acme, function (): void {
            expect(app(RoleManager::class)->assignablePermissionNames())->toHaveCount(16)->each->toStartWith('core.');

            expect(fn () => app(RoleManager::class)->create($this->owner, 'Sales', null, ['test_crm.leads.view']))
                ->toThrow(ValidationException::class, 'not available to this workspace');
        });

        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/team/roles', ['name' => 'Sales', 'permissions' => ['test_crm.leads.view']])
            ->assertSessionHasErrors('permissions');

        // The role editor does not even offer them.
        $this->get('http://acme.localhost/team/roles/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('catalogue', fn ($catalogue): bool => collect($catalogue)->pluck('module')->all() === ['core']));
    });

    it('shows nothing of the module in the sidebar, the dashboard or the permission list', function () {
        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                expect(collect($props['navigation'])->pluck('key')->all())->toBe(['workspace', 'apps', 'manage'])
                    ->and(collect($props['navigation'])->flatMap(fn (array $section): array => array_column($section['items'], 'key'))->all())->not->toContain('leads')
                    ->and(array_column($props['widgets'], 'key'))->toBe(['core.team', 'core.security', 'core.activity'])
                    ->and($props['auth']['permissions'])->not->toContain('test_crm.leads.view')
                    // Compiled into the build is not "installed".
                    ->and($props['installedApps'])->toBe(0);
            });

        expect(PipelineWidget::$invocations)->toBe(0);
    });
});

describe('a workspace that is entitled', function () {
    beforeEach(function () {
        $this->entitle($this->acme, 'test_crm');
    });

    it('makes the module automation definitions available only inside an entitled workspace', function () {
        expect($this->inTenant(
            $this->acme,
            fn () => app(AutomationRegistry::class)->trigger('test_crm.lead.created'),
        ))->toBe(LeadCreatedTrigger::class)
            ->and($this->inTenant(
                $this->globex,
                fn () => app(AutomationRegistry::class)->trigger('test_crm.lead.created'),
            ))->toBeNull();
    });

    it('holds the module\'s permissions as owner and can grant them to roles', function () {
        expect(($this->can)($this->owner, 'test_crm.leads.view'))->toBeTrue()
            ->and(($this->can)($this->owner, 'test_crm.leads.edit'))->toBeTrue();

        $sales = $this->createRole($this->acme, 'Sales', ['test_crm.leads.view']);
        $rep = $this->addMember($this->acme, $sales);

        expect(($this->can)($rep, 'test_crm.leads.view'))->toBeTrue()
            ->and(($this->can)($rep, 'test_crm.leads.edit'))->toBeFalse();
    });

    it('opens the module\'s routes to people who hold the permission, and only to them', function () {
        $rep = $this->addMember($this->acme, $this->createRole($this->acme, 'Sales', ['test_crm.leads.view']));
        $employee = $this->addMember($this->acme, Role::EMPLOYEE);

        $this->actingAsMember($this->acme, $rep)->get('http://acme.localhost/test-crm/leads')->assertOk()->assertExactJson(['leads' => []]);

        // Entitled workspace, no permission: a plain 403 - the app is no secret here.
        $this->startNewBrowserSession()->actingAsMember($this->acme, $employee)->get('http://acme.localhost/test-crm/leads')->assertForbidden();

        $this->startNewBrowserSession()->get('http://acme.localhost/test-crm/leads')->assertRedirect('http://acme.localhost/login');
        $this->get('http://acme.localhost/test-crm/ping')->assertOk();
    });

    it('shows the module in the sidebar and on the dashboard to people who may see it', function () {
        $rep = $this->addMember($this->acme, $this->createRole($this->acme, 'Sales', ['test_crm.leads.view']));
        $employee = $this->addMember($this->acme, Role::EMPLOYEE);

        $this->actingAsMember($this->acme, $rep)
            ->get('http://acme.localhost/')
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];
                $apps = collect($props['navigation'])->firstWhere('key', 'apps');

                expect($apps['items'])->toBe([[
                    'key' => 'test_leads',
                    'route' => 'test_crm.leads.index',
                    'href' => 'http://acme.localhost/test-crm/leads',
                    'icon' => 'target',
                    'active' => 'test_crm.leads.*',
                ]])
                    ->and(array_column($props['widgets'], 'key'))->toBe(['test_crm.pipeline', 'core.security'])
                    ->and($props['installedApps'])->toBe(1);
            });

        expect(PipelineWidget::$invocations)->toBe(1);

        // Same workspace, someone without the permission: the widget's data is never even computed.
        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $employee)
            ->get('http://acme.localhost/')
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $apps = collect($props['navigation'])->firstWhere('key', 'apps');

                expect(array_column($apps['items'], 'key'))->toBe(['appStore'])
                    ->and(array_column($props['widgets'], 'key'))->toBe(['core.team', 'core.security']);
            });

        expect(PipelineWidget::$invocations)->toBe(1);
    });

    it('is entitled alone: another workspace still has nothing', function () {
        $theirOwner = $this->ownerOf($this->globex);

        expect(($this->can)($theirOwner, 'test_crm.leads.view', $this->globex))->toBeFalse();

        $this->actingAsOwner($this->globex)->get('http://globex.localhost/test-crm/leads')->assertNotFound();
        $this->get('http://globex.localhost/test-crm/ping')->assertNotFound();

        $this->get('http://globex.localhost/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('installedApps', 0));
    });

    it('loses everything again the moment the entitlement or the workspace lapses', function () {
        $sales = $this->createRole($this->acme, 'Sales', ['test_crm.leads.view']);
        $rep = $this->addMember($this->acme, $sales);

        expect(($this->can)($rep, 'test_crm.leads.view'))->toBeTrue();

        // Suspended workspace: no module at all, core included.
        $this->acme->update(['status' => TenantStatus::Suspended]);

        expect(($this->can)($rep, 'test_crm.leads.view'))->toBeFalse()
            ->and(($this->can)($this->owner, 'core.members.view'))->toBeFalse();

        // Active again, but the entitlement is gone: the grant stays in the table and means nothing.
        $this->acme->update(['status' => TenantStatus::Active]);
        $this->app->forgetInstance(EntitlementChecker::class);
        $this->app->forgetScopedInstances();

        expect(app(RoleManager::class)->grantedNames($sales))->toBe(['test_crm.leads.view'])
            ->and(($this->can)($rep, 'test_crm.leads.view'))->toBeFalse();

        $this->actingAsMember($this->acme, $rep)->get('http://acme.localhost/test-crm/leads')->assertNotFound();
    });
});
