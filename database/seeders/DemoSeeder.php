<?php

namespace Database\Seeders;

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Local demo data: one Super Admin and two workspaces with a few people in
 * each. Every account uses the password "password".
 *
 * Refuses to run anywhere but the local environment.
 */
class DemoSeeder extends Seeder
{
    private const string PASSWORD = 'password';

    public function run(TenantProvisioner $provisioner): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder only runs in the local environment.');
        }

        PlatformUser::query()->firstOrCreate(
            ['email' => 'admin@oyosend.test'],
            ['name' => 'Platform Admin', 'password' => self::PASSWORD, 'role' => PlatformRole::SuperAdmin],
        );

        // Belongs to both workspaces, to exercise the workspace switcher.
        $shared = $this->user('Farhan Kabir', 'farhan@oyosend.test');

        $this->workspace($provisioner, 'Acme Trading', 'acme', 'en', $this->user('Ayesha Rahman', 'owner@acme.test'), [
            [Role::ADMIN, $this->user('Tanvir Hasan', 'admin@acme.test')],
            [Role::MANAGER, $this->user('Nusrat Jahan', 'manager@acme.test')],
            [Role::EMPLOYEE, $this->user('Imran Hossain', 'employee@acme.test')],
            [Role::MANAGER, $shared],
        ]);

        $this->workspace($provisioner, 'Globex Bangladesh', 'globex', 'bn', $this->user('রফিকুল ইসলাম', 'owner@globex.test'), [
            [Role::ADMIN, $this->user('সাদিয়া আক্তার', 'admin@globex.test')],
            [Role::EMPLOYEE, $this->user('মাহমুদ হাসান', 'employee@globex.test')],
            [Role::EMPLOYEE, $shared],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: User}>  $members  role name, user
     */
    private function workspace(
        TenantProvisioner $provisioner,
        string $name,
        string $slug,
        string $locale,
        User $owner,
        array $members,
    ): void {
        if (Tenant::query()->where('slug', $slug)->exists()) {
            return;
        }

        $tenant = $provisioner->provision($name, $slug, $owner, ['locale' => $locale]);

        app(TenantContext::class)->run($tenant, function () use ($members): void {
            foreach ($members as [$roleName, $user]) {
                TenantMembership::query()->create([
                    'user_id' => $user->getKey(),
                    'status' => MembershipStatus::Active,
                    'joined_at' => Carbon::now(),
                ]);

                $user->assignRole(Role::query()->forCurrentTenant()->where('name', $roleName)->firstOrFail());
            }
        });
    }

    private function user(string $name, string $email): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => self::PASSWORD],
        );

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();
        }

        return $user;
    }
}
