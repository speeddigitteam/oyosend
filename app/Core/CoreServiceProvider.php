<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Access\AccessChecker;
use App\Core\Access\Console\SyncPermissionsCommand;
use App\Core\Access\Models\Role;
use App\Core\Access\PermissionResolver;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\Models\AuditLog;
use App\Core\Automation\Triggers\MemberJoinedTrigger;
use App\Core\Dashboard\Widgets\RecentActivityWidget;
use App\Core\Dashboard\Widgets\SecurityWidget;
use App\Core\Dashboard\Widgets\TeamWidget;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Entitlements\CoreModulesEntitlementChecker;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Identity\TwoFactor\TwoFactorService;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Tenancy\Models\Tenant;
use App\Platform\Models\PlatformUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Gate;

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
            version: '1.0.0',
            core: true,
            permissions: [
                new PermissionDefinition('core.members.view', 'members'),
                new PermissionDefinition('core.members.invite', 'members'),
                new PermissionDefinition('core.members.manage', 'members', sensitive: true),
                new PermissionDefinition('core.roles.view', 'roles'),
                new PermissionDefinition('core.roles.manage', 'roles', sensitive: true),
                new PermissionDefinition('core.audit.view', 'audit', sensitive: true),
                new PermissionDefinition('core.settings.manage', 'settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('dashboard', 'core.dashboard', 'layout-dashboard', section: 'workspace', order: 10),
                new NavigationItem('members', 'core.members.index', 'users', 'core.members.view', 'manage', 10, 'core.members.*'),
                new NavigationItem('roles', 'core.roles.index', 'shield-check', 'core.roles.view', 'manage', 20, 'core.roles.*'),
                new NavigationItem('audit', 'core.audit.index', 'scroll-text', 'core.audit.view', 'manage', 30, 'core.audit.*'),
            ],
            widgets: [
                new WidgetDefinition('core.team', 'TeamWidget', TeamWidget::class, 'core.members.view', span: 7, order: 10),
                new WidgetDefinition('core.security', 'SecurityWidget', SecurityWidget::class, span: 5, order: 20),
                new WidgetDefinition('core.activity', 'ActivityWidget', RecentActivityWidget::class, 'core.audit.view', span: 12, order: 30),
            ],
            triggers: [
                MemberJoinedTrigger::class,
            ],
            morphMap: [
                'user' => User::class,
                'platform_user' => PlatformUser::class,
                'tenant' => Tenant::class,
                'membership' => TenantMembership::class,
                'invitation' => Invitation::class,
                'role' => Role::class,
                'audit_log' => AuditLog::class,
            ],
        );
    }

    public function register(): void
    {
        parent::register();

        $this->app->scoped(AccessChecker::class);
        $this->app->scoped(PermissionResolver::class);
        $this->app->scoped(AuditLogger::class);

        // Replaced by the App Store's entitlement store in Phase 3.
        $this->app->bind(EntitlementChecker::class, CoreModulesEntitlementChecker::class);

        // Replay protection must hold across workspaces, so it is not tenant-prefixed.
        $this->app->when(TwoFactorService::class)
            ->needs(CacheRepository::class)
            ->give(fn ($app): CacheRepository => $app->make('cache.central'));
    }

    protected function bootModule(): void
    {
        $this->loadTenantRoutes(base_path('routes/tenant.php'));
        $this->registerGate();

        // Reset links open in the workspace the reset was requested from.
        ResetPassword::createUrlUsing(static fn (CanResetPassword $notifiable, string $token): string => route(
            'auth.password.reset',
            ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()],
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class]);
        }
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
