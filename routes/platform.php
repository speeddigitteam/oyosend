<?php

use App\Core\Http\Controllers\LocaleController;
use App\Modules\Ai\Http\Controllers\Platform\ProviderAccountController as AiProviderAccountController;
use App\Modules\Communication\Http\Controllers\Platform\ProviderAccountController;
use App\Platform\Http\Controllers\AuditLogController;
use App\Platform\Http\Controllers\Auth\LoginController;
use App\Platform\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Platform\Http\Controllers\Auth\TwoFactorSetupController;
use App\Platform\Http\Controllers\DashboardController;
use App\Platform\Http\Controllers\ImpersonationController;
use App\Platform\Http\Controllers\IntegrationController;
use App\Platform\Http\Controllers\InvoiceController;
use App\Platform\Http\Controllers\PlanController;
use App\Platform\Http\Controllers\SettingsController;
use App\Platform\Http\Controllers\TenantController;
use App\Platform\Http\Controllers\TenantSubscriptionController;
use App\Platform\Http\Controllers\Website\BlogController as WebsiteBlogController;
use App\Platform\Http\Controllers\Website\BrandController as WebsiteBrandController;
use App\Platform\Http\Controllers\Website\CompanyController as WebsiteCompanyController;
use App\Platform\Http\Controllers\Website\EnquiryController as WebsiteEnquiryController;
use App\Platform\Http\Controllers\Website\TextController as WebsiteTextController;
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

        // The public website (docs/WEBSITE.md): its contact form's messages, the company's details, its words and its blog.
        Route::get('website/messages', [WebsiteEnquiryController::class, 'index'])
            ->middleware('can:platform.website.view')
            ->name('website.messages.index');

        Route::middleware('can:platform.website.manage')->group(function () {
            Route::post('website/messages/{enquiry}/handled', [WebsiteEnquiryController::class, 'handled'])->name('website.messages.handled');
            Route::delete('website/messages/{enquiry}/handled', [WebsiteEnquiryController::class, 'reopen'])->name('website.messages.reopen');
            Route::delete('website/messages/{enquiry}', [WebsiteEnquiryController::class, 'destroy'])->name('website.messages.destroy');

            Route::get('website/company', [WebsiteCompanyController::class, 'edit'])->name('website.company.edit');
            Route::put('website/company', [WebsiteCompanyController::class, 'update'])->name('website.company.update');
            Route::post('website/brand/{asset}', [WebsiteBrandController::class, 'store'])->name('website.brand.store');
            Route::delete('website/brand/{asset}', [WebsiteBrandController::class, 'destroy'])->name('website.brand.destroy');

            Route::get('website/texts', [WebsiteTextController::class, 'index'])->name('website.texts.index');
            Route::put('website/texts/{section}', [WebsiteTextController::class, 'update'])->name('website.texts.update');
            Route::delete('website/texts/{section}', [WebsiteTextController::class, 'reset'])->name('website.texts.reset');

            // The blog (ADR 0037): articles, their pictures and a preview drawn by the website itself.
            Route::get('website/blog', [WebsiteBlogController::class, 'index'])->name('website.blog.index');
            Route::get('website/blog/create', [WebsiteBlogController::class, 'create'])->name('website.blog.create');
            Route::post('website/blog', [WebsiteBlogController::class, 'store'])->name('website.blog.store');
            Route::post('website/blog/media', [WebsiteBlogController::class, 'upload'])->middleware('throttle:30,1')->name('website.blog.media');
            Route::post('website/blog/preview', [WebsiteBlogController::class, 'preview'])->middleware('throttle:60,1')->name('website.blog.preview');
            Route::get('website/blog/{post}/edit', [WebsiteBlogController::class, 'edit'])->name('website.blog.edit');
            Route::put('website/blog/{post}', [WebsiteBlogController::class, 'update'])->name('website.blog.update');
            Route::delete('website/blog/{post}', [WebsiteBlogController::class, 'destroy'])->name('website.blog.destroy');
        });

        Route::middleware('can:platform.settings.manage')->group(function () {
            Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

            // Payment gateway credentials (write-only, encrypted; falls back to the environment).
            // docs/adr/0028-operator-managed-integration-credentials.md.
            Route::put('settings/gateways/{gateway}', [SettingsController::class, 'updateGateway'])->name('settings.gateways.update');
            Route::delete('settings/gateways/{gateway}', [SettingsController::class, 'clearGateway'])->name('settings.gateways.clear');

            // Social platform apps (Facebook, LinkedIn, X) and the Meta Ads app credentials (write-only,
            // encrypted; falls back to the environment). docs/adr/0029-social-and-meta-ads-panel-credentials.md.
            Route::get('settings/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
            Route::put('settings/integrations/{group}/{name}', [IntegrationController::class, 'update'])->name('integrations.update');
            Route::delete('settings/integrations/{group}/{name}', [IntegrationController::class, 'clear'])->name('integrations.clear');

            // Messaging provider accounts (email, SMS, WhatsApp). The controller belongs to the communication
            // module because the adapters do; secrets are write-only (docs/adr/0013).
            Route::get('messaging', [ProviderAccountController::class, 'index'])->name('messaging.index');
            Route::post('messaging', [ProviderAccountController::class, 'store'])->name('messaging.store');
            Route::put('messaging/{account}', [ProviderAccountController::class, 'update'])->name('messaging.update');
            Route::delete('messaging/{account}', [ProviderAccountController::class, 'destroy'])->name('messaging.destroy');
            Route::post('messaging/{account}/test', [ProviderAccountController::class, 'test'])->name('messaging.test');

            // AI provider accounts. The controller belongs to the ai module; the API key is write-only.
            Route::get('ai/providers', [AiProviderAccountController::class, 'index'])->name('ai.index');
            Route::post('ai/providers', [AiProviderAccountController::class, 'store'])->name('ai.store');
            Route::put('ai/providers/{account}', [AiProviderAccountController::class, 'update'])->name('ai.update');
            Route::delete('ai/providers/{account}', [AiProviderAccountController::class, 'destroy'])->name('ai.destroy');
            Route::post('ai/providers/{account}/test', [AiProviderAccountController::class, 'test'])->name('ai.test');
            Route::post('ai/providers/{account}/default', [AiProviderAccountController::class, 'makeDefault'])->name('ai.default');
        });
    });
});
