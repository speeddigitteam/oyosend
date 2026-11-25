<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Access\AccessChecker;
use App\Core\Access\Console\SyncPermissionsCommand;
use App\Core\Access\Models\Role;
use App\Core\Access\PermissionResolver;
use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\Contracts\VoiceInput;
use App\Core\Ai\NullTextAssistant;
use App\Core\Ai\NullVoiceInput;
use App\Core\AppStore\Console\RunModuleSchedulesCommand;
use App\Core\AppStore\Console\SyncAppsCommand;
use App\Core\AppStore\Models\AppEntitlement;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\AppStore\Models\SubscriptionItem;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\Models\AuditLog;
use App\Core\Automation\Triggers\MemberJoinedTrigger;
use App\Core\Billing\Console\RunBillingCycleCommand;
use App\Core\Billing\CurrentSubscription;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Billing\Gateways\AamarPayGateway;
use App\Core\Billing\Gateways\GatewayCredentials;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Gateways\SslCommerzGateway;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\ChannelNumberDirectory;
use App\Core\Communication\Contracts\ChannelNumberRegistry;
use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Contracts\InboxSignals;
use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Contracts\MediaGateway;
use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Contracts\TemplateCatalogue;
use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\NullChannelGateway;
use App\Core\Communication\NullChannelNumbers;
use App\Core\Communication\NullConsentRecorder;
use App\Core\Communication\NullInboxSignals;
use App\Core\Communication\NullInteractionRecorder;
use App\Core\Communication\NullMarketingAudience;
use App\Core\Communication\NullMediaGateway;
use App\Core\Communication\NullNotifier;
use App\Core\Communication\NullTemplateCatalogue;
use App\Core\Communication\NullUnsubscribeLinks;
use App\Core\Contacts\Automation\CompanyCreatedTrigger;
use App\Core\Contacts\Automation\ContactCreatedTrigger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\NullCrmAudience;
use App\Core\Crm\NullLeadIntake;
use App\Core\Dashboard\Widgets\RecentActivityWidget;
use App\Core\Dashboard\Widgets\SecurityWidget;
use App\Core\Dashboard\Widgets\TeamWidget;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Entitlements\PlanEntitlementChecker;
use App\Core\Identity\Auth\WorkspaceHandoff;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Identity\TwoFactor\TwoFactorService;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Settings\IntegrationCredentials;
use App\Core\Settings\PlatformSettings;
use App\Core\Social\Contracts\ConnectedPages;
use App\Core\Social\NullConnectedPages;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Website\Models\BlogPost;
use App\Core\Website\Models\WebsiteEnquiry;
use App\Core\Website\SiteApps;
use App\Core\Website\Support\WebsiteTextLoader;
use App\Core\Website\WebsiteTexts;
use App\Platform\Models\PlatformUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The `core` module: the workspace foundation every tenant has.
 *
 * It is a module like any other - it declares a manifest and loads its own
 * routes - which keeps the foundation honest about the contract optional
 * apps are built against.
 */
final class CoreServiceProvider extends ModuleServiceProvider
{
    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core',
            name: 'Workspace',
            version: '1.2.0',
            core: true,
            permissions: [
                new PermissionDefinition('core.members.view', 'members'),
                new PermissionDefinition('core.members.invite', 'members'),
                new PermissionDefinition('core.members.manage', 'members', sensitive: true),
                new PermissionDefinition('core.roles.view', 'roles'),
                new PermissionDefinition('core.roles.manage', 'roles', sensitive: true),
                new PermissionDefinition('core.organization.view', 'organization'),
                new PermissionDefinition('core.organization.manage', 'organization'),
                // Contacts and companies share these three; each can be
                // narrowed to a person's own, department's or branch's records.
                new PermissionDefinition('core.contacts.view', 'contacts', scopable: true),
                new PermissionDefinition('core.contacts.manage', 'contacts', scopable: true),
                new PermissionDefinition('core.contacts.delete', 'contacts', scopable: true, sensitive: true),
                new PermissionDefinition('core.audit.view', 'audit', sensitive: true),
                new PermissionDefinition('core.settings.manage', 'settings', sensitive: true),
                new PermissionDefinition('core.billing.view', 'billing', sensitive: true),
                new PermissionDefinition('core.billing.manage', 'billing', sensitive: true),
                new PermissionDefinition('core.apps.view', 'apps'),
                new PermissionDefinition('core.apps.manage', 'apps', sensitive: true),
            ],
            navigation: [
                new NavigationItem('dashboard', 'core.dashboard', 'layout-dashboard', section: 'workspace', order: 10),
                new NavigationItem('contacts', 'core.contacts.index', 'contact', 'core.contacts.view', 'workspace', 20, 'core.contacts.*'),
                new NavigationItem('members', 'core.members.index', 'users', 'core.members.view', 'manage', 10, 'core.members.*'),
                new NavigationItem('roles', 'core.roles.index', 'shield-check', 'core.roles.view', 'manage', 20, 'core.roles.*'),
                new NavigationItem('organization', 'core.organization.index', 'network', 'core.organization.view', 'manage', 25, 'core.organization.*'),
                new NavigationItem('audit', 'core.audit.index', 'scroll-text', 'core.audit.view', 'manage', 30, 'core.audit.*'),
                new NavigationItem('workspaceSettings', 'core.settings.workspace', 'settings', 'core.settings.manage', 'manage', 40, 'core.settings.workspace*'),
                new NavigationItem('billing', 'core.billing.index', 'credit-card', 'core.billing.view', 'manage', 50, 'core.billing.*'),
                new NavigationItem('appStore', 'core.apps.index', 'package', 'core.apps.view', 'apps', 1, 'core.apps.*'),
            ],
            widgets: [
                new WidgetDefinition('core.team', 'TeamWidget', TeamWidget::class, 'core.members.view', span: 7, order: 10),
                new WidgetDefinition('core.security', 'SecurityWidget', SecurityWidget::class, span: 5, order: 20),
                new WidgetDefinition('core.activity', 'ActivityWidget', RecentActivityWidget::class, 'core.audit.view', span: 12, order: 30),
            ],
            triggers: [
                MemberJoinedTrigger::class,
                ContactCreatedTrigger::class,
                CompanyCreatedTrigger::class,
            ],
            morphMap: [
                'user' => User::class,
                'platform_user' => PlatformUser::class,
                'tenant' => Tenant::class,
                'membership' => TenantMembership::class,
                'invitation' => Invitation::class,
                'role' => Role::class,
                'audit_log' => AuditLog::class,
                'branch' => Branch::class,
                'department' => Department::class,
                'contact' => Contact::class,
                'company' => Company::class,
                'plan' => Plan::class,
                'subscription' => Subscription::class,
                'invoice' => Invoice::class,
                'payment' => Payment::class,
                'catalog_app' => CatalogApp::class,
                'app_installation' => AppInstallation::class,
                'app_entitlement' => AppEntitlement::class,
                'subscription_item' => SubscriptionItem::class,
                'website_enquiry' => WebsiteEnquiry::class,
                'blog_post' => BlogPost::class,
            ],
            limits: [
                // Seats: everyone in the workspace, and everyone invited who can still join.
                new LimitDefinition('core.members', LimitKind::Gauge, static fn (): int => TenantMembership::query()->count()
                    + Invitation::query()->pending()->count()),
            ],
        );
    }

    public function register(): void
    {
        parent::register();

        $this->app->scoped(AccessChecker::class);
        $this->app->scoped(PermissionResolver::class);
        $this->app->scoped(AuditLogger::class);
        $this->app->scoped(CurrentSubscription::class);

        // One checker per request, so the subscription is read once however
        // many permissions, menu entries and widgets ask about it.
        $this->app->scoped(PlanEntitlementChecker::class);
        $this->app->bind(EntitlementChecker::class, static fn ($app): EntitlementChecker => $app->make(PlanEntitlementChecker::class));

        // Platform data must not land in a tenant's cache namespace: replay
        // protection has to hold across workspaces, operator settings are the
        // same for all of them, and a handoff is redeemed in another one.
        foreach ([TwoFactorService::class, PlatformSettings::class, WorkspaceHandoff::class] as $platformWide) {
            $this->app->when($platformWide)
                ->needs(CacheRepository::class)
                ->give(fn ($app): CacheRepository => $app->make('cache.central'));
        }

        $this->app->scoped(PlatformSettings::class);
        $this->app->scoped(IntegrationCredentials::class);

        // The website's words an operator changed in the Super Admin panel, laid over lang/{locale}/site*.php by the
        // translation loader, so every template keeps calling __() (docs/WEBSITE.md). Platform data: central cache.
        $this->app->extend('translation.loader', static fn (Loader $loader, $app): Loader => new WebsiteTextLoader(
            $loader,
            static fn (): array => $app->make(WebsiteTexts::class)->overrides(),
        ));
        $this->app->singleton(WebsiteTexts::class, static function ($app): WebsiteTexts {
            $loader = $app->make('translation.loader');

            return new WebsiteTexts(
                $app->make('cache.central'),
                $loader instanceof WebsiteTextLoader ? $loader->files() : $loader,
                $app->make(SiteApps::class),
            );
        });

        // What other modules call to notify members and to append to the interaction timeline. The
        // `communication` module rebinds both; until then (or without it) they honestly record nothing.
        $this->app->bindIf(Notifier::class, NullNotifier::class);
        $this->app->bindIf(InteractionRecorder::class, NullInteractionRecorder::class);
        $this->app->bindIf(MarketingAudience::class, NullMarketingAudience::class);
        $this->app->bindIf(UnsubscribeLinks::class, NullUnsubscribeLinks::class);
        $this->app->bindIf(ConsentRecorder::class, NullConsentRecorder::class);
        $this->app->bindIf(ChannelGateway::class, NullChannelGateway::class);
        // ADR 0022: workspace numbers, WhatsApp media and the provider template catalogue; `communication` rebinds them.
        $this->app->bindIf(ChannelNumberDirectory::class, NullChannelNumbers::class);
        $this->app->bindIf(ChannelNumberRegistry::class, NullChannelNumbers::class);
        $this->app->bindIf(MediaGateway::class, NullMediaGateway::class);
        $this->app->bindIf(TemplateCatalogue::class, NullTemplateCatalogue::class);
        // ADR 0035: the realtime "a conversation changed" signal for open inbox pages; `communication` rebinds it.
        $this->app->bindIf(InboxSignals::class, NullInboxSignals::class);
        // Outside enquiries into the CRM and the CRM's customer groups as a campaign audience (ADR 0023).
        // The `crm` module rebinds both; without it a capture is honestly `unavailable` and the audience empty.
        $this->app->bindIf(LeadIntake::class, NullLeadIntake::class);
        $this->app->bindIf(CrmAudience::class, NullCrmAudience::class);
        // The Facebook Pages a workspace connected in Social, token-free (ADR 0027); `social` rebinds it.
        $this->app->bindIf(ConnectedPages::class, NullConnectedPages::class);
        // The `ai` module rebinds this with the gateway-backed assistant (ADR 0024).
        $this->app->bindIf(TextAssistant::class, NullTextAssistant::class);
        // Voice input for the palette and the assistant (ADR 0038); `ai` rebinds it.
        $this->app->bindIf(VoiceInput::class, NullVoiceInput::class);

        // Schedule idempotency is platform-wide. It must not be affected by
        // the cache prefix of whichever tenant is being dispatched.
        $this->app->when(RunModuleSchedulesCommand::class)
            ->needs(CacheRepository::class)
            ->give(fn ($app): CacheRepository => $app->make('cache.central'));

        $this->app->singleton(GatewayRegistry::class, static function ($app): GatewayRegistry {
            $registry = new GatewayRegistry;
            $credentials = $app->make(GatewayCredentials::class);

            // Credentials come from the Super Admin panel first (encrypted, write-only), then the
            // environment as the fallback default. A gateway with neither is not configured and is
            // not offered. See docs/adr/0028-operator-managed-integration-credentials.md.
            $ssl = $credentials->for('sslcommerz');
            $registry->register(new SslCommerzGateway(
                $app->make(HttpFactory::class),
                $ssl['secrets']['store_id'] ?? null,
                $ssl['secrets']['store_password'] ?? null,
                $ssl['sandbox'],
            ));

            $aamar = $credentials->for('aamarpay');
            $registry->register(new AamarPayGateway(
                $app->make(HttpFactory::class),
                $aamar['secrets']['store_id'] ?? null,
                $aamar['secrets']['signature_key'] ?? null,
                $aamar['sandbox'],
            ));

            return $registry;
        });
    }

    protected function bootModule(): void
    {
        $this->loadTenantRoutes(base_path('routes/tenant.php'));
        $this->loadCallbackRoutes();
        $this->registerGate();

        // Overlay the operator-set Social and Meta Ads credentials onto the live config, so every
        // existing config() reader transparently gets the panel value; the environment is the
        // fallback (docs/adr/0029). A failure here must never break boot: config() is read lazily,
        // and IntegrationCredentials already falls back to the environment on any read error.
        try {
            $this->app->make(IntegrationCredentials::class)->apply($this->app->make('config'));
        } catch (\Throwable) {
            // Keep the environment defaults; boot must not fail over operator credentials.
        }

        // Reset links open in the workspace the reset was requested from.
        ResetPassword::createUrlUsing(static fn (CanResetPassword $notifiable, string $token): string => route(
            'auth.password.reset',
            ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()],
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncPermissionsCommand::class,
                SyncAppsCommand::class,
                RunModuleSchedulesCommand::class,
                RunBillingCycleCommand::class,
            ]);
        }
    }

    /**
     * Where payment gateways call back: on the workspace's host, with tenant
     * resolution but none of the `web` group. See routes/tenant-callbacks.php.
     */
    private function loadCallbackRoutes(): void
    {
        Route::domain('{tenant}.'.$this->app->make(TenantHost::class)->domain())
            ->middleware('tenant.callback')
            ->group(base_path('routes/tenant-callbacks.php'));
    }

    /**
     * Route every module permission through the access checker.
     *
     * Returning a boolean (never null) for a registered permission means no
     * later gate or policy can grant what the checker denied. Abilities that
     * are not module permissions fall through to ordinary policies.
     */
    private function registerGate(): void
    {
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            if ($user instanceof PlatformUser) {
                return str_starts_with($ability, 'platform.') ? $user->role->allows($ability) : null;
            }

            if (! $user instanceof User) {
                return null;
            }

            if ($this->app->make(ModuleRegistry::class)->findPermission($ability) === null) {
                return null;
            }

            return $this->app->make(AccessChecker::class)->allows($user, $ability);
        });
    }
}
