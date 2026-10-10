<?php

declare(strict_types=1);

use App\Core\Access\DefaultRoles;
use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Domain;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->syncPermissions();
    $this->provisioner = app(TenantProvisioner::class);
    $this->founder = User::factory()->create();
});

it('creates a usable workspace: tenant, subdomain, system roles and owner', function () {
    $tenant = $this->provisioner->provision('Acme Trading', 'acme', $this->founder);

    // Lower-case ULID, like every other key in the schema.
    expect($tenant->id)->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/')
        ->and($tenant->name)->toBe('Acme Trading')
        ->and($tenant->slug)->toBe('acme')
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->locale)->toBe('en')
        ->and($tenant->timezone)->toBe('Asia/Dhaka')
        ->and($tenant->currency)->toBe('BDT');

    $domain = Domain::query()->where('tenant_id', $tenant->id)->sole();

    expect($domain->domain)->toBe('acme')
        ->and($domain->type)->toBe(Domain::TYPE_SUBDOMAIN)
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->verified_at)->not->toBeNull();

    $roles = Role::query()->where('tenant_id', $tenant->id)->get()->keyBy('name');

    expect($roles->keys()->sort()->values()->all())->toBe(['Admin', 'Employee', 'Manager', 'Owner'])
        ->and($roles->every(fn (Role $role): bool => $role->is_system && $role->guard_name === 'web'))->toBeTrue();

    foreach (DefaultRoles::definitions() as $name => $definition) {
        $expected = $definition['permissions'];
        sort($expected);

        expect(app(RoleManager::class)->grantedNames($roles[$name]))->toBe($expected);
    }

    $membership = TenantMembership::forTenant($tenant)->sole();

    expect($membership->user_id)->toBe($this->founder->id)
        ->and($membership->is_owner)->toBeTrue()
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->joined_at)->not->toBeNull();

    // The owner holds the Owner role, in this tenant only.
    expect(DB::table('model_has_roles')->where('model_id', $this->founder->id)->get()->map(fn ($row): array => (array) $row)->all())
        ->toBe([[
            'role_id' => $roles['Owner']->id,
            'model_type' => 'user',
            'model_id' => $this->founder->id,
            'tenant_id' => $tenant->id,
        ]]);
});

it('applies the optional attributes', function () {
    $trialEnds = now()->addDays(14)->startOfSecond();

    $tenant = $this->provisioner->provision('Globex', 'globex', $this->founder, [
        'locale' => 'bn',
        'timezone' => 'Asia/Kolkata',
        'currency' => 'USD',
        'trial_ends_at' => $trialEnds,
    ]);

    expect($tenant->refresh()->locale)->toBe('bn')
        ->and($tenant->timezone)->toBe('Asia/Kolkata')
        ->and($tenant->currency)->toBe('USD')
        ->and($tenant->trial_ends_at->equalTo($trialEnds))->toBeTrue();
});

it('provisions a workspace that is not active yet, complete with its roles', function (TenantStatus|string $status) {
    $tenant = $this->provisioner->provision('Later Ltd', 'later', $this->founder, ['status' => $status]);

    $expected = $status instanceof TenantStatus ? $status : TenantStatus::from($status);

    expect($tenant->refresh()->status)->toBe($expected)
        ->and($tenant->isAccessible())->toBeFalse()
        ->and(Role::query()->where('tenant_id', $tenant->id)->count())->toBe(4)
        ->and(app(RoleManager::class)->grantedNames($this->roleOf($tenant, 'Admin')))->toHaveCount(12)
        ->and(TenantMembership::forTenant($tenant)->where('is_owner', true)->count())->toBe(1);
})->with([
    'pending' => [TenantStatus::Pending],
    'suspended' => [TenantStatus::Suspended],
    'given as a string' => ['pending'],
]);

it('normalises the address', function () {
    expect($this->provisioner->provision('Acme', '  AcMe  ', $this->founder)->slug)->toBe('acme');
});

it('rejects an address that is reserved or not a valid subdomain label', function (string $slug) {
    expect(fn () => $this->provisioner->provision('Nope', $slug, $this->founder))
        ->toThrow(InvalidArgumentException::class);

    expect(Tenant::query()->count())->toBe(0)
        ->and(Domain::query()->count())->toBe(0);
})->with([
    'the admin panel' => ['admin'],
    'reserved' => ['www'],
    'reserved, upper case' => ['API'],
    'reserved, padded' => [' horizon '],
    'empty' => [''],
    'leading hyphen' => ['-acme'],
    'trailing hyphen' => ['acme-'],
    'underscore' => ['ac_me'],
    'space' => ['ac me'],
    'dot' => ['ac.me'],
    'non-ASCII' => ['আকমে'],
    'too long' => [str_repeat('a', 64)],
    'path characters' => ['acme/evil'],
]);

it('refuses an address that is already taken and leaves nothing behind', function () {
    $this->provisioner->provision('Acme', 'acme', $this->founder);

    $rolesBefore = Role::query()->count();

    expect(fn () => $this->provisioner->provision('Acme Two', 'acme', User::factory()->create()))
        ->toThrow(QueryException::class);

    expect(Tenant::query()->where('slug', 'acme')->count())->toBe(1)
        ->and(Tenant::query()->where('name', 'Acme Two')->exists())->toBeFalse()
        ->and(Role::query()->count())->toBe($rolesBefore);
});

it('refuses to provision before the permission catalogue is synced, and rolls back', function () {
    Permission::query()->delete();

    expect(fn () => $this->provisioner->provision('Acme', 'acme', $this->founder))
        ->toThrow(RuntimeException::class, 'permissions:sync');

    expect(Tenant::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(TenantMembership::withoutTenancy()->count())->toBe(0);

    // The failed, rolled-back tenant is not left active for whatever runs next.
    expect(app(TenantContext::class)->check())->toBeFalse();
});

it('returns to the context it was called from', function () {
    $acme = $this->provisioner->provision('Acme', 'acme', $this->founder);
    $context = app(TenantContext::class);

    expect($context->check())->toBeFalse();

    $this->inTenant($acme, function () use ($acme, $context): void {
        $this->provisioner->provision('Globex', 'globex', User::factory()->create());

        expect($context->id())->toBe($acme->id);
    });
});

it('lets one person own several workspaces with separate roles', function () {
    $acme = $this->provisioner->provision('Acme', 'acme', $this->founder);
    $globex = $this->provisioner->provision('Globex', 'globex', $this->founder);

    expect($this->founder->memberships()->where('is_owner', true)->count())->toBe(2)
        ->and($this->inTenant($acme, fn (): array => $this->founder->roles()->pluck('roles.id')->all()))
        ->toBe([$this->roleOf($acme, 'Owner')->id])
        ->and($this->inTenant($globex, fn (): array => $this->founder->roles()->pluck('roles.id')->all()))
        ->toBe([$this->roleOf($globex, 'Owner')->id]);
});
