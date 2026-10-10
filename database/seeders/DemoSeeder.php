<?php

namespace Database\Seeders;

use App\Core\Access\Models\Role;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\SubscriptionManager;
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
 * Local demo data: one Super Admin, four example plans and two workspaces
 * with a few people in each. Every account uses the password "password".
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

        $plans = $this->plans();

        // Belongs to both workspaces, to exercise the workspace switcher.
        $shared = $this->user('Farhan Kabir', 'farhan@oyosend.test');

        $this->workspace($provisioner, $plans['business'], 'Acme Trading', 'acme', 'en', $this->user('Ayesha Rahman', 'owner@acme.test'), [
            [Role::ADMIN, $this->user('Tanvir Hasan', 'admin@acme.test')],
            [Role::MANAGER, $this->user('Nusrat Jahan', 'manager@acme.test')],
            [Role::EMPLOYEE, $this->user('Imran Hossain', 'employee@acme.test')],
            [Role::MANAGER, $shared],
        ]);

        $this->workspace($provisioner, $plans['free'], 'Globex Bangladesh', 'globex', 'bn', $this->user('রফিকুল ইসলাম', 'owner@globex.test'), [
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
        Plan $plan,
        string $name,
        string $slug,
        string $locale,
        User $owner,
        array $members,
    ): void {
        $existing = Tenant::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            // A local database from before plans existed: put the workspace on
            // its plan and leave everything else in it as it is.
            app(TenantContext::class)->run($existing, function () use ($plan): void {
                $subscriptions = app(SubscriptionManager::class);

                if ($subscriptions->current() === null) {
                    $subscriptions->start($plan, BillingInterval::Monthly);
                }
            });

            return;
        }

        $tenant = $provisioner->provision($name, $slug, $owner, ['locale' => $locale]);

        app(TenantContext::class)->run($tenant, function () use ($members, $plan): void {
            // Acme starts a trial of a paid plan; Globex is on the free one.
            app(SubscriptionManager::class)->start($plan, BillingInterval::Monthly);

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

    /**
     * Example plans, so that sign-up, billing and limits can be tried
     * locally. The names follow the specification; the prices and limits are
     * placeholders for local development and are seeded nowhere else.
     *
     * @return array<string, Plan> keyed by plan key
     */
    private function plans(): array
    {
        $definitions = [
            'free' => ['Free', 'For trying things out with a small team.', 0, null, 0, 5, 10],
            'starter' => ['Starter', 'For a small business getting organised.', 150000, 1500000, 14, 10, 20],
            'business' => ['Business', 'For a growing company with several teams.', 450000, 4500000, 14, 50, 30],
            'enterprise' => ['Enterprise', 'For a large organisation. No limit on members.', 1200000, 12000000, 14, null, 40],
        ];

        $plans = [];

        foreach ($definitions as $key => [$name, $description, $monthly, $yearly, $trialDays, $members, $order]) {
            $plans[$key] = Plan::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'description' => $description,
                'currency' => 'BDT',
                'monthly_amount' => $monthly,
                'yearly_amount' => $yearly,
                'trial_days' => $trialDays,
                'limits' => $members === null ? [] : ['core.members' => $members],
                'sort_order' => $order,
            ]);
        }

        return $plans;
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
