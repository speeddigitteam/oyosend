<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Links\Enums\LinkKind;
use App\Modules\Links\Models\Link;
use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The README's local setup is `migrate --seed` followed by signing in with
 * the demo accounts, so the seeder is part of what has to keep working.
 */

it('seeds only the permission catalogue outside local development', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Tenant::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(PlatformUser::query()->count())->toBe(0)
        ->and(DB::table('permissions')->count())->toBeGreaterThan(0);
});

it('refuses to create demo accounts anywhere but local development', function () {
    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'local environment');

    expect(User::query()->count())->toBe(0);
});

describe('in local development', function () {
    beforeEach(function () {
        // Local only while seeding: the requests below must run the way every other test's do.
        $this->seedLocally = function (): void {
            $this->app->detectEnvironment(fn (): string => 'local');

            try {
                $this->seed(DatabaseSeeder::class);
            } finally {
                $this->app->detectEnvironment(fn (): string => 'testing');
            }
        };

        ($this->seedLocally)();
    });

    it('creates one Super Admin and two workspaces with their people', function () {
        $admin = PlatformUser::query()->sole();

        expect($admin->email)->toBe('admin@oyosend.test')
            ->and($admin->role)->toBe(PlatformRole::SuperAdmin)
            // Enrolment happens at the first sign-in.
            ->and($admin->hasEnabledTwoFactor())->toBeFalse();

        $tenants = Tenant::query()->orderBy('slug')->get()->keyBy('slug');

        expect($tenants->keys()->all())->toBe(['acme', 'globex'])
            ->and($tenants['acme']->locale)->toBe('en')
            ->and($tenants['globex']->locale)->toBe('bn');

        $rolesIn = fn (Tenant $tenant): array => $this->inTenant($tenant, fn (): array => TenantMembership::query()
            ->with('user.roles')
            ->get()
            ->mapWithKeys(fn (TenantMembership $membership): array => [$membership->user->email => $membership->user->roles->pluck('name')->all()])
            ->sortKeys()
            ->all());

        expect($rolesIn($tenants['acme']))->toBe([
            'admin@acme.test' => [Role::ADMIN],
            'employee@acme.test' => [Role::EMPLOYEE],
            'farhan@oyosend.test' => [Role::MANAGER],
            'manager@acme.test' => [Role::MANAGER],
            'owner@acme.test' => [Role::OWNER],
        ])->and($rolesIn($tenants['globex']))->toBe([
            'admin@globex.test' => [Role::ADMIN],
            'employee@globex.test' => [Role::EMPLOYEE],
            'farhan@oyosend.test' => [Role::EMPLOYEE],
            'owner@globex.test' => [Role::OWNER],
        ]);

        // Seeding leaves no workspace active behind it.
        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('lets the demo accounts sign in with the documented password', function () {
        $this->post('http://acme.localhost/login', ['email' => 'owner@acme.test', 'password' => 'password'])
            ->assertRedirect('http://acme.localhost');

        $this->assertAuthenticated('web');

        // Someone from the other workspace does not get in here.
        $this->startNewBrowserSession()
            ->post('http://acme.localhost/login', ['email' => 'owner@globex.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    });

    it('gives each workspace starter links in its own language, owned by the owner', function () {
        $globex = Tenant::query()->where('slug', 'globex')->sole();

        $links = $this->inTenant($globex, fn () => Link::query()->with('owner')->get());

        expect($links)->toHaveCount(17)
            ->and($links->pluck('owner.is_owner')->unique()->all())->toBe([true])
            ->and($links->pluck('title'))->toContain('জাতীয় রাজস্ব বোর্ড (এনবিআর)')
            ->and($links->firstWhere('kind', LinkKind::Email)?->address)->toEndWith('@globex.test')
            ->and($links->where('kind', '!=', LinkKind::Email)->every(fn (Link $link): bool => str_starts_with($link->address, 'https://')))->toBeTrue();
    });

    it('can be run again without duplicating anything', function () {
        ($this->seedLocally)();

        expect(Tenant::query()->count())->toBe(2)
            ->and(User::query()->count())->toBe(8)
            ->and(PlatformUser::query()->count())->toBe(1)
            ->and(TenantMembership::withoutTenancy()->count())->toBe(9)
            ->and(Link::withoutTenancy()->count())->toBe(34);
    });
});
