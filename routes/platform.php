<?php

use App\Core\Http\Controllers\LocaleController;
use App\Platform\Http\Controllers\AuditLogController;
use App\Platform\Http\Controllers\Auth\LoginController;
use App\Platform\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Platform\Http\Controllers\Auth\TwoFactorSetupController;
use App\Platform\Http\Controllers\DashboardController;
use App\Platform\Http\Controllers\ImpersonationController;
use App\Platform\Http\Controllers\InvoiceController;
use App\Platform\Http\Controllers\PlanController;
use App\Platform\Http\Controllers\SettingsController;
use App\Platform\Http\Controllers\TenantController;
use App\Platform\Http\Controllers\TenantSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super Admin panel - admin.APP_DOMAIN
|--------------------------------------------------------------------------
|
| Loaded inside the `web` + `platform` middleware groups with the `platform.`
| name prefix. Everything here runs in the central context with the
| `platform` guard; no tenant is ever active.
|
| A workspace is addressed as `{workspace}` (its id), never `{tenant}`: that
| name belongs to the subdomain of workspace routes. An invoice is looked up
| by the controller, across tenants on purpose, so `{invoice}` is a plain id.
|
*/

Route::post('locale', LocaleController::class)->name('locale.update');

Route::middleware('guest:platform')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.verify');
});

Route::middleware('auth:platform')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Enrolment is the only thing an account without two-factor can reach.
    Route::get('two-factor/setup', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('two-factor/setup', [TwoFactorSetupController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('two-factor.confirm');

    Route::middleware('platform.2fa')->group(function () {
        Route::get('/', DashboardController::class)
            ->middleware('can:platform.dashboard.view')
            ->name('dashboard');

        // Workspaces
        Route::get('tenants', [TenantController::class, 'index'])
            ->middleware('can:platform.tenants.view')
            ->name('tenants.index');
        Route::middleware('can:platform.tenants.manage')->group(function () {
            Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
            Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
            Route::post('tenants/{workspace}/approve', [TenantController::class, 'approve'])->name('tenants.approve');
            Route::post('tenants/{workspace}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
            Route::post('tenants/{workspace}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');
        });
        Route::get('tenants/{workspace}', [TenantController::class, 'show'])
            ->middleware('can:platform.tenants.view')
            ->name('tenants.show');

        // Support access: into a workspace as its owner, with a recorded reason.
        Route::post('tenants/{workspace}/impersonate', [ImpersonationController::class, 'store'])
            ->middleware(['can:platform.tenants.impersonate', 'throttle:10,1'])
            ->name('tenants.impersonate');

        // What only an operator may do to a workspace's subscription.
        Route::middleware('can:platform.billing.manage')->group(function () {
            Route::post('tenants/{workspace}/subscription', [TenantSubscriptionController::class, 'grant'])
                ->name('tenants.subscription.grant');
            Route::post('tenants/{workspace}/subscription/trial', [TenantSubscriptionController::class, 'extendTrial'])
                ->name('tenants.subscription.trial');
        });

        // Plans
        Route::get('plans', [PlanController::class, 'index'])
            ->middleware('can:platform.plans.view')
            ->name('plans.index');
        Route::middleware('can:platform.plans.manage')->group(function () {
            Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
            Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        });

        // Invoices and payments
        Route::middleware('can:platform.billing.view')->group(function () {
            Route::get('billing/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('billing/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        });
        Route::middleware('can:platform.billing.manage')->group(function () {
            Route::post('billing/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
                ->name('invoices.payments.store');
            Route::post('billing/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        });

        Route::get('audit-log', [AuditLogController::class, 'index'])
            ->middleware('can:platform.audit.view')
            ->name('audit.index');

        Route::middleware('can:platform.settings.manage')->group(function () {
            Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
