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
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ProviderAccount;
use App\Modules\Links\Actions\LinkManager;
use App\Modules\Links\Enums\LinkKind;
use App\Modules\Links\Models\Link;
use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Starter links for the Links app: kind, address (`{domain}` becomes the workspace's demo mail domain), English
     * title, Bengali title, English folder, Bengali folder, pinned.
     *
     * @var list<array{0: LinkKind, 1: string, 2: string, 3: string, 4: string, 5: string, 6: bool}>
     */
    private const array LINKS = [
        [LinkKind::Website, 'https://www.nbr.gov.bd', 'National Board of Revenue (NBR)', 'জাতীয় রাজস্ব বোর্ড (এনবিআর)', 'Government', 'সরকারি', true],
        [LinkKind::Tool, 'https://vat.gov.bd', 'VAT Online (IVAS)', 'ভ্যাট অনলাইন (আইভাস)', 'Government', 'সরকারি', true],
        [LinkKind::Tool, 'https://etaxnbr.gov.bd', 'Income tax e-Return', 'আয়কর ই-রিটার্ন', 'Government', 'সরকারি', false],
        [LinkKind::Tool, 'https://secure.incometax.gov.bd', 'e-TIN registration', 'ই-টিআইএন নিবন্ধন', 'Government', 'সরকারি', false],
        [LinkKind::Website, 'https://roc.gov.bd', 'RJSC - company registration', 'আরজেএসসি - কোম্পানি নিবন্ধন', 'Government', 'সরকারি', false],
        [LinkKind::Website, 'https://www.bb.org.bd', 'Bangladesh Bank', 'বাংলাদেশ ব্যাংক', 'Government', 'সরকারি', false],
        [LinkKind::Document, 'https://bdlaws.minlaw.gov.bd', 'Laws of Bangladesh', 'বাংলাদেশের আইন', 'Government', 'সরকারি', false],
        [LinkKind::Website, 'https://www.bkash.com', 'bKash', 'বিকাশ', 'Payments', 'পেমেন্ট', false],
        [LinkKind::Website, 'https://nagad.com.bd', 'Nagad', 'নগদ', 'Payments', 'পেমেন্ট', false],
        [LinkKind::Tool, 'https://mail.google.com', 'Gmail', 'জিমেইল', 'Office tools', 'অফিস টুল', true],
        [LinkKind::Tool, 'https://drive.google.com', 'Google Drive', 'গুগল ড্রাইভ', 'Office tools', 'অফিস টুল', false],
        [LinkKind::Tool, 'https://meet.google.com', 'Google Meet', 'গুগল মিট', 'Office tools', 'অফিস টুল', false],
        [LinkKind::Tool, 'https://calendar.google.com', 'Google Calendar', 'গুগল ক্যালেন্ডার', 'Office tools', 'অফিস টুল', false],
        [LinkKind::Tool, 'https://translate.google.com', 'Google Translate', 'গুগল অনুবাদ', 'Office tools', 'অফিস টুল', false],
        [LinkKind::Email, 'accounts@{domain}', 'Accounts', 'হিসাব বিভাগ', 'Team email', 'বিভাগের ইমেইল', false],
        [LinkKind::Email, 'hr@{domain}', 'Human resources', 'মানবসম্পদ বিভাগ', 'Team email', 'বিভাগের ইমেইল', false],
        [LinkKind::Email, 'support@{domain}', 'Customer support', 'গ্রাহক সহায়তা', 'Team email', 'বিভাগের ইমেইল', true],
    ];

    public function run(TenantProvisioner $provisioner): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder only runs in the local environment.');
        }

        PlatformUser::query()->firstOrCreate(
            ['email' => 'admin@oyosend.test'],
            ['name' => 'Platform Admin', 'password' => self::PASSWORD, 'role' => PlatformRole::SuperAdmin],
        );

        $this->emailProviders();

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

        $this->links('acme');
        $this->links('globex');
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
            'free' => ['Free', 'For trying things out with a small team.', 0, null, 0, 5, 10, []],
            'starter' => ['Starter', 'For a small business getting organised.', 150000, 1500000, 14, 10, 20, []],
            'business' => ['Business', 'For a growing company with several teams.', 450000, 4500000, 14, 50, 30, ['crm', 'projects', 'invoicing', 'hrm', 'payroll', 'talent', 'automation', 'communication', 'email_marketing', 'social', 'meta_ads', 'sms_marketing', 'whatsapp_marketing', 'support', 'reports', 'notes', 'links', 'calendar']],
            'enterprise' => ['Enterprise', 'For a large organisation. No limit on members.', 1200000, 12000000, 14, null, 40, ['crm', 'projects', 'invoicing', 'hrm', 'payroll', 'talent', 'automation', 'ai', 'communication', 'email_marketing', 'social', 'meta_ads', 'sms_marketing', 'whatsapp_marketing', 'support', 'reports', 'notes', 'links', 'calendar']],
        ];

        $plans = [];

        foreach ($definitions as $key => [$name, $description, $monthly, $yearly, $trialDays, $members, $order, $modules]) {
            $plans[$key] = Plan::query()->updateOrCreate(['key' => $key], [
                'name' => $name,
                'description' => $description,
                'currency' => 'BDT',
                'monthly_amount' => $monthly,
                'yearly_amount' => $yearly,
                'trial_days' => $trialDays,
                'modules' => $modules,
                'limits' => $members === null ? [] : ['core.members' => $members],
                'sort_order' => $order,
            ]);
        }

        return $plans;
    }

    /**
     * Two demo SMTP accounts for the Super Admin "Messaging providers" page. The first points at a local mail
     * catcher (Mailpit on 127.0.0.1:1025, no login) and works only while one is running; the second is a
     * disabled template whose host and password are placeholders to be replaced with real ones.
     */
    private function emailProviders(): void
    {
        ProviderAccount::query()->firstOrCreate(
            ['channel' => Channel::Email->value, 'name' => 'Demo SMTP - local Mailpit'],
            [
                'provider' => 'smtp',
                'is_enabled' => true,
                'settings' => ['host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'starttls', 'from_address' => 'demo@oyosend.test', 'from_name' => 'Oyosend Demo'],
                'secrets' => ['secret' => 'demo-no-login'],
                'tenant_ids' => null,
            ],
        );

        ProviderAccount::query()->firstOrCreate(
            ['channel' => Channel::Email->value, 'name' => 'Demo SMTP - sandbox (replace credentials)'],
            [
                'provider' => 'smtp',
                'is_enabled' => false,
                'settings' => ['host' => 'sandbox.smtp.mailtrap.io', 'port' => 2525, 'encryption' => 'starttls', 'username' => 'replace-me', 'from_address' => 'demo@oyosend.test', 'from_name' => 'Oyosend Demo'],
                'secrets' => ['secret' => 'replace-me'],
                'tenant_ids' => null,
            ],
        );
    }

    /**
     * Starter links owned by the workspace owner, in the workspace's language. Seeded once: a workspace that already
     * has links is left alone. They show once the workspace is entitled to `links` (a plan or an App Store install).
     */
    private function links(string $slug): void
    {
        if (! Schema::hasTable('links')) {
            return;
        }

        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

        app(TenantContext::class)->run($tenant, function () use ($tenant, $slug): void {
            if (Link::query()->exists()) {
                return;
            }

            $owner = TenantMembership::query()->where('is_owner', true)->first();
            $bengali = $tenant->locale === 'bn';
            $links = app(LinkManager::class);

            foreach (self::LINKS as [$kind, $address, $title, $titleBn, $folder, $folderBn, $pinned]) {
                $links->create([
                    'kind' => $kind,
                    'address' => str_replace('{domain}', $slug.'.test', $address),
                    'title' => $bengali ? $titleBn : $title,
                    'folder' => $bengali ? $folderBn : $folder,
                    'is_pinned' => $pinned,
                ], $owner);
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
