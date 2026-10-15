<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Audit\Models\AuditLog;
use App\Core\CoreServiceProvider;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModulesServiceProvider;
use App\Core\Modules\PermissionDefinition;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\CRM\CRMServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Relations\Relation;

function manifest(string $key, array $overrides = []): ModuleManifest
{
    return new ModuleManifest(...['key' => $key, 'name' => ucfirst($key), 'version' => '1.0.0', ...$overrides]);
}

describe('a module manifest', function () {
    it('accepts a lower-case identifier as its key', function (string $key) {
        expect(manifest($key)->key)->toBe($key);
    })->with(['crm', 'hrm2', 'email_marketing', 'a']);

    it('rejects a key that could not prefix a permission', function (string $key) {
        expect(fn () => manifest($key))->toThrow(InvalidArgumentException::class, 'Invalid module key');
    })->with([
        'upper case' => ['CRM'],
        'leading digit' => ['2fa'],
        'hyphen' => ['email-marketing'],
        'dot' => ['crm.leads'],
        'space' => ['crm leads'],
        'empty' => [''],
        'leading underscore' => ['_crm'],
        'trailing newline' => ["crm\n"],
    ]);

    it('only carries permissions prefixed with its own key', function () {
        expect(fn () => manifest('crm', ['permissions' => [new PermissionDefinition('core.members.view', 'members')]]))
            ->toThrow(InvalidArgumentException::class, 'must be prefixed with its module key [crm.]');

        expect(fn () => manifest('crm', ['permissions' => [new PermissionDefinition('crmx.leads.view', 'leads')]]))
            ->toThrow(InvalidArgumentException::class);

        expect(manifest('crm', ['permissions' => [new PermissionDefinition('crm.leads.view', 'leads')]])->permissions)->toHaveCount(1);
    });

    it('is not a core module unless it says so', function () {
        expect(manifest('crm')->core)->toBeFalse()
            ->and(manifest('core', ['core' => true])->core)->toBeTrue();
    });
});

describe('a permission definition', function () {
    it('derives its module from the name', function () {
        $permission = new PermissionDefinition('crm.leads.edit', 'leads', scopable: true, sensitive: true);

        expect($permission->module())->toBe('crm')
            ->and($permission->group)->toBe('leads')
            ->and($permission->scopable)->toBeTrue()
            ->and($permission->sensitive)->toBeTrue();
    });

    it('treats a name without a dot as its own module', function () {
        expect((new PermissionDefinition('standalone', 'misc'))->module())->toBe('standalone');
    });
});

describe('the module registry', function () {
    beforeEach(function () {
        $this->registry = new ModuleRegistry;
    });

    it('holds the registered manifests by key', function () {
        $core = manifest('core', ['core' => true]);
        $crm = manifest('crm');

        $this->registry->register($core);
        $this->registry->register($crm);

        expect(array_keys($this->registry->all()))->toBe(['core', 'crm'])
            ->and($this->registry->has('crm'))->toBeTrue()
            ->and($this->registry->has('hrm'))->toBeFalse()
            ->and($this->registry->get('crm'))->toBe($crm)
            ->and($this->registry->find('crm'))->toBe($crm)
            ->and($this->registry->find('hrm'))->toBeNull();

        expect(fn () => $this->registry->get('hrm'))->toThrow(InvalidArgumentException::class, 'Unknown module [hrm]');
    });

    it('refuses a second module with the same key', function () {
        $this->registry->register(manifest('crm'));

        expect(fn () => $this->registry->register(manifest('crm', ['name' => 'Another CRM'])))
            ->toThrow(InvalidArgumentException::class, 'Module [crm] is already registered');

        expect($this->registry->get('crm')->name)->toBe('Crm');
    });

    it('indexes the permissions of every module and finds them by name', function () {
        $this->registry->register(manifest('core', ['permissions' => [new PermissionDefinition('core.members.view', 'members')]]));

        expect(array_keys($this->registry->permissions()))->toBe(['core.members.view']);

        // The index follows modules registered after it was first built.
        $this->registry->register(manifest('crm', ['permissions' => [
            new PermissionDefinition('crm.leads.view', 'leads'),
            new PermissionDefinition('crm.leads.edit', 'leads'),
        ]]));

        expect(array_keys($this->registry->permissions()))->toBe(['core.members.view', 'crm.leads.view', 'crm.leads.edit'])
            ->and($this->registry->findPermission('crm.leads.edit')?->module())->toBe('crm')
            ->and($this->registry->findPermission('crm.leads.delete'))->toBeNull()
            ->and($this->registry->findPermission('viewHorizon'))->toBeNull();
    });

    it('refuses a permission that is declared twice', function () {
        $this->registry->register(manifest('crm', ['permissions' => [
            new PermissionDefinition('crm.leads.view', 'leads'),
            new PermissionDefinition('crm.leads.view', 'pipeline'),
        ]]));

        expect(fn () => $this->registry->permissions())
            ->toThrow(InvalidArgumentException::class, 'Permission [crm.leads.view] is registered twice');
    });

    it('reports modules whose dependencies are not in the build', function () {
        $this->registry->register(manifest('core', ['core' => true]));
        $this->registry->register(manifest('crm', ['dependencies' => ['core']]));

        expect($this->registry->missingDependencies())->toBe([]);

        $this->registry->register(manifest('invoicing', ['dependencies' => ['core', 'crm', 'billing', 'contacts']]));

        expect($this->registry->missingDependencies())->toBe(['invoicing' => ['billing', 'contacts']]);
    });
});

describe('the application\'s own registry', function () {
    it('contains the core module, as declared by its provider', function () {
        $registry = app(ModuleRegistry::class);
        $core = $registry->get('core');

        $corePermissions = array_values(array_filter(
            array_keys($registry->permissions()),
            fn (string $permission): bool => str_starts_with($permission, 'core.'),
        ));

        expect(array_keys($registry->all()))->toBe(['core', 'crm', 'projects', 'invoicing', 'hrm', 'payroll', 'talent', 'automation', 'ai', 'communication', 'email_marketing', 'social', 'meta_ads', 'sms_marketing', 'support', 'whatsapp_marketing', 'reports', 'notes', 'telephony'])
            ->and($core->core)->toBeTrue()
            ->and($core)->toBe(app()->getProvider(CoreServiceProvider::class)->manifest())
            ->and($registry->get('crm'))->toBe(app()->getProvider(CRMServiceProvider::class)->manifest())
            ->and($registry->get('crm')->permissions)->toHaveCount(24)
            ->and($registry->get('projects'))->toBe(app()->getProvider(ProjectsServiceProvider::class)->manifest())
            ->and($registry->get('projects')->permissions)->toHaveCount(19)
            ->and($registry->get('projects')->routes)->toContain('projects.task-options.index')
            ->and($registry->get('invoicing')->permissions)->toHaveCount(12)
            ->and($registry->get('invoicing')->routes)->toContain('invoicing.invoices.payments.store')
            ->and($registry->get('hrm')->permissions)->toHaveCount(20)
            ->and($registry->get('hrm')->routes)->toContain('hrm.leave.requests.decision')
            ->and($registry->get('payroll')->permissions)->toHaveCount(22)
            ->and($registry->get('talent')->permissions)->toHaveCount(30)
            ->and($registry->get('talent')->dependencies)->toBe(['core', 'hrm'])
            ->and(array_keys($registry->permissions()))->toHaveCount(273)
            ->and($corePermissions)->toEqualCanonicalizing([
                'core.members.view', 'core.members.invite', 'core.members.manage',
                'core.roles.view', 'core.roles.manage', 'core.audit.view', 'core.settings.manage',
                'core.organization.view', 'core.organization.manage',
                'core.contacts.view', 'core.contacts.manage', 'core.contacts.delete',
                'core.billing.view', 'core.billing.manage',
                'core.apps.view', 'core.apps.manage',
            ])
            ->and($registry->missingDependencies())->toBe([]);
    });

    it('is the same instance wherever it is resolved', function () {
        expect(app(ModuleRegistry::class))->toBe(app(ModuleRegistry::class));
    });

    it('refuses to boot with a module whose dependency is missing', function () {
        app(ModuleRegistry::class)->register(manifest('ledger', ['dependencies' => ['billing']]));

        expect(fn () => (new ModulesServiceProvider(app()))->boot())
            ->toThrow(RuntimeException::class, 'ledger needs billing');
    });

    it('never stores a class name in a polymorphic column: every model has a morph alias', function () {
        $map = Relation::morphMap();

        expect($map)->toMatchArray([
            'user' => User::class,
            'platform_user' => PlatformUser::class,
            'tenant' => Tenant::class,
            'membership' => TenantMembership::class,
            'invitation' => Invitation::class,
            'role' => Role::class,
            'audit_log' => AuditLog::class,
        ])
            ->and(Relation::requiresMorphMap())->toBeTrue()
            ->and((new User)->getMorphClass())->toBe('user');
    });
});
