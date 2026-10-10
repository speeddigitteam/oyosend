<?php

use App\Core\AppStore\Http\Controllers\AppStoreController;
use App\Core\Audit\Http\Controllers\AuditLogController;
use App\Core\Billing\Http\Controllers\BillingController;
use App\Core\Billing\Http\Controllers\InvoiceController;
use App\Core\Contacts\Http\Controllers\CompanyController;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Dashboard\Http\Controllers\DashboardController;
use App\Core\Http\Controllers\LocaleController;
use App\Core\Identity\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Core\Identity\Http\Controllers\Auth\LoginController;
use App\Core\Identity\Http\Controllers\Auth\NewPasswordController;
use App\Core\Identity\Http\Controllers\Auth\PasswordResetLinkController;
use App\Core\Identity\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Core\Identity\Http\Controllers\Auth\WorkspaceHandoffController;
use App\Core\Identity\Http\Controllers\Settings\ProfileController;
use App\Core\Identity\Http\Controllers\Settings\SecurityController;
use App\Core\Identity\Http\Controllers\Settings\TwoFactorController;
use App\Core\Identity\Http\Controllers\Team\InvitationController;
use App\Core\Identity\Http\Controllers\Team\MemberController;
use App\Core\Identity\Http\Controllers\Team\RoleController;
use App\Core\Organization\Http\Controllers\BranchController;
use App\Core\Organization\Http\Controllers\DepartmentController;
use App\Core\Organization\Http\Controllers\OrganizationController;
use App\Core\Settings\Http\Controllers\OnboardingController;
use App\Core\Settings\Http\Controllers\WorkspaceSettingsController;
use App\Platform\Impersonation\Http\WorkspaceImpersonationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Core workspace routes - {tenant}.APP_DOMAIN
|--------------------------------------------------------------------------
|
| Loaded by CoreServiceProvider inside the `web` + `tenant` middleware groups,
| so the tenant is already resolved from the subdomain for every route here.
|
| Rules for anything added to a workspace:
|  - bind tenant-scoped models only (TenantMembership, never User);
|  - name the permission on the route with `can:`;
|  - a non-core module's routes also carry its `entitled:` middleware, which
|    ModuleServiceProvider::loadTenantRoutes() adds for you;
|  - anything that is part of working in the workspace goes inside the
|    `subscribed` group (the paywall). Only signing out, personal settings
|    and billing stay reachable when the subscription has ended.
|
| The stateless gateway callbacks are in routes/tenant-callbacks.php.
|
*/

Route::middleware('guest:web')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('auth.login');
    Route::post('login', [LoginController::class, 'store'])->name('auth.login.store');

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('auth.two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('auth.two-factor.verify');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('auth.password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('auth.password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.password.update');
});

// Reachable signed in or signed out.
Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('auth.invitations.show');
Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('auth.invitations.accept');

Route::get('auth/handoff', [WorkspaceHandoffController::class, 'consume'])
    ->middleware('throttle:20,1')
    ->name('auth.handoff');

// Where a support session opened in the Super Admin panel arrives.
Route::get('auth/impersonate', [WorkspaceImpersonationController::class, 'begin'])
    ->middleware('throttle:20,1')
    ->name('auth.impersonate');

Route::post('locale', LocaleController::class)->name('core.locale.update');

Route::middleware(['auth:web', 'auth.session', 'member'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('auth.logout');
    Route::post('workspaces/{workspace}/switch', [WorkspaceHandoffController::class, 'issue'])
        ->name('core.workspaces.switch');

    Route::post('auth/impersonate/stop', [WorkspaceImpersonationController::class, 'stop'])->name('auth.impersonate.stop');

    // Billing: what the workspace pays the platform. Outside the paywall,
    // because this is where a locked workspace is brought back.
    Route::middleware('can:core.billing.view')->group(function () {
        Route::get('billing', [BillingController::class, 'index'])->name('core.billing.index');
        Route::get('billing/invoices/{invoice}', [InvoiceController::class, 'show'])->name('core.billing.invoices.show');
    });
    Route::middleware('can:core.billing.manage')->group(function () {
        Route::post('billing/subscription', [BillingController::class, 'change'])->name('core.billing.subscription.change');
        Route::post('billing/subscription/cancel', [BillingController::class, 'cancel'])->name('core.billing.subscription.cancel');
        Route::post('billing/subscription/resume', [BillingController::class, 'resume'])->name('core.billing.subscription.resume');
        Route::post('billing/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])
            ->middleware('throttle:10,1')
            ->name('core.billing.invoices.pay');
    });

    // Everything that is part of working in the workspace.
    Route::middleware('subscribed')->group(function () {
        Route::get('/', DashboardController::class)->name('core.dashboard');

        // App Store: compiled first-party modules only. Paid checkout also
        // requires billing control; a verified invoice is what grants access.
        Route::middleware('can:core.apps.view')->group(function () {
            Route::get('apps', [AppStoreController::class, 'index'])->name('core.apps.index');
            Route::get('apps/my', [AppStoreController::class, 'mine'])->name('core.apps.mine');
            Route::get('apps/{app}', [AppStoreController::class, 'show'])
                ->where('app', '[a-z][a-z0-9_]*')
                ->name('core.apps.show');
        });
        Route::middleware('can:core.apps.manage')->group(function () {
            Route::post('apps/{app}/install', [AppStoreController::class, 'install'])->where('app', '[a-z][a-z0-9_]*')->name('core.apps.install');
            Route::post('apps/{app}/activate', [AppStoreController::class, 'activate'])->where('app', '[a-z][a-z0-9_]*')->name('core.apps.activate');
            Route::post('apps/{app}/deactivate', [AppStoreController::class, 'deactivate'])->where('app', '[a-z][a-z0-9_]*')->name('core.apps.deactivate');
            Route::post('apps/{app}/reinstall', [AppStoreController::class, 'reinstall'])->where('app', '[a-z][a-z0-9_]*')->name('core.apps.reinstall');
            Route::post('apps/{app}/upgrade', [AppStoreController::class, 'upgrade'])->where('app', '[a-z][a-z0-9_]*')->name('core.apps.upgrade');
            Route::put('apps/{app}/settings', [AppStoreController::class, 'updateSettings'])->where('app', '[a-z][a-z0-9_]*')->name('core.apps.settings.update');
            Route::delete('apps/{app}', [AppStoreController::class, 'uninstall'])->where('app', '[a-z][a-z0-9_]*')->name('core.apps.uninstall');
            Route::post('apps/subscriptions/{item}/cancel', [AppStoreController::class, 'cancel'])->middleware('can:core.billing.manage')->name('core.apps.subscriptions.cancel');
            Route::post('apps/subscriptions/{item}/resume', [AppStoreController::class, 'resume'])->middleware('can:core.billing.manage')->name('core.apps.subscriptions.resume');
        });
        Route::post('apps/{app}/purchase', [AppStoreController::class, 'purchase'])
            ->where('app', '[a-z][a-z0-9_]*')
            ->middleware(['can:core.apps.manage', 'can:core.billing.manage'])
            ->name('core.apps.purchase');

        // First-run setup of a workspace someone signed up for.
        Route::middleware('can:core.settings.manage')->group(function () {
            Route::get('onboarding', [OnboardingController::class, 'show'])->name('core.onboarding.show');
            Route::post('onboarding', [OnboardingController::class, 'store'])->name('core.onboarding.store');
        });

        // Team
        Route::get('team/members', [MemberController::class, 'index'])
            ->middleware('can:core.members.view')
            ->name('core.members.index');
        Route::patch('team/members/{membership}', [MemberController::class, 'update'])
            ->middleware('can:core.members.manage')
            ->name('core.members.update');
        Route::delete('team/members/{membership}', [MemberController::class, 'destroy'])
            ->middleware('can:core.members.manage')
            ->name('core.members.destroy');

        Route::post('team/invitations', [InvitationController::class, 'store'])
            ->middleware('can:core.members.invite')
            ->name('core.invitations.store');
        Route::delete('team/invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->middleware('can:core.members.invite')
            ->name('core.invitations.destroy');

        // Roles
        Route::get('team/roles', [RoleController::class, 'index'])
            ->middleware('can:core.roles.view')
            ->name('core.roles.index');
        Route::middleware('can:core.roles.manage')->group(function () {
            Route::get('team/roles/create', [RoleController::class, 'create'])->name('core.roles.create');
            Route::post('team/roles', [RoleController::class, 'store'])->name('core.roles.store');
            Route::get('team/roles/{role}/edit', [RoleController::class, 'edit'])->name('core.roles.edit');
            Route::put('team/roles/{role}', [RoleController::class, 'update'])->name('core.roles.update');
            Route::delete('team/roles/{role}', [RoleController::class, 'destroy'])->name('core.roles.destroy');
        });

        // Audit trail
        Route::get('audit-log', [AuditLogController::class, 'index'])
            ->middleware('can:core.audit.view')
            ->name('core.audit.index');

        // Organisation: branches and departments
        Route::get('organization', [OrganizationController::class, 'index'])
            ->middleware('can:core.organization.view')
            ->name('core.organization.index');
        Route::middleware('can:core.organization.manage')->group(function () {
            Route::post('organization/branches', [BranchController::class, 'store'])->name('core.branches.store');
            Route::put('organization/branches/{branch}', [BranchController::class, 'update'])->name('core.branches.update');
            Route::delete('organization/branches/{branch}', [BranchController::class, 'destroy'])->name('core.branches.destroy');
            Route::post('organization/departments', [DepartmentController::class, 'store'])->name('core.departments.store');
            Route::put('organization/departments/{department}', [DepartmentController::class, 'update'])->name('core.departments.update');
            Route::delete('organization/departments/{department}', [DepartmentController::class, 'destroy'])->name('core.departments.destroy');
        });

        // Contacts and companies: the shared customer directory. The
        // controllers narrow each permission to the scope it was granted with.
        Route::middleware('can:core.contacts.view')->group(function () {
            Route::get('contacts', [ContactController::class, 'index'])->name('core.contacts.index');
            Route::get('companies', [CompanyController::class, 'index'])->name('core.contacts.companies.index');
        });
        Route::middleware('can:core.contacts.manage')->group(function () {
            Route::post('contacts', [ContactController::class, 'store'])->name('core.contacts.store');
            Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('core.contacts.update');
            Route::post('companies', [CompanyController::class, 'store'])->name('core.contacts.companies.store');
            Route::put('companies/{company}', [CompanyController::class, 'update'])->name('core.contacts.companies.update');
        });
        Route::middleware('can:core.contacts.delete')->group(function () {
            Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('core.contacts.destroy');
            Route::delete('companies/{company}', [CompanyController::class, 'destroy'])->name('core.contacts.companies.destroy');
        });

        // Workspace settings
        Route::middleware('can:core.settings.manage')->group(function () {
            Route::get('settings/workspace', [WorkspaceSettingsController::class, 'edit'])->name('core.settings.workspace');
            Route::put('settings/workspace', [WorkspaceSettingsController::class, 'update'])->name('core.settings.workspace.update');
            Route::post('settings/workspace/logo', [WorkspaceSettingsController::class, 'storeLogo'])
                ->middleware('throttle:10,1')
                ->name('core.settings.workspace.logo.store');
            Route::delete('settings/workspace/logo', [WorkspaceSettingsController::class, 'destroyLogo'])
                ->name('core.settings.workspace.logo.destroy');
        });
    });

    // Personal settings
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('core.settings.profile');
    Route::put('settings/profile', [ProfileController::class, 'update'])->name('core.settings.profile.update');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('core.settings.security');
    Route::put('settings/password', [SecurityController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('core.settings.password.update');

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('settings/two-factor', [TwoFactorController::class, 'store'])->name('core.settings.two-factor.enable');
        Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('core.settings.two-factor.confirm');
        Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('core.settings.two-factor.recovery-codes');
        Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])->name('core.settings.two-factor.disable');
    });
});
