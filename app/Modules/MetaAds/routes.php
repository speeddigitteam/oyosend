<?php

declare(strict_types=1);

use App\Modules\MetaAds\Http\Controllers\AccountController;
use App\Modules\MetaAds\Http\Controllers\CampaignController;
use App\Modules\MetaAds\Http\Controllers\PerformanceController;
use App\Modules\MetaAds\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('meta-ads')->group(function (): void {
    // Performance dashboard and its CSV export.
    Route::get('/', [PerformanceController::class, 'index'])
        ->middleware('can:meta_ads.insights.view')
        ->name('meta_ads.performance.index');
    Route::get('performance/export', [PerformanceController::class, 'export'])
        ->middleware(['can:meta_ads.insights.export', 'throttle:10,1'])
        ->name('meta_ads.performance.export');

    // Campaigns, ad sets and ads; the only changes this app makes to Meta.
    Route::get('campaigns', [CampaignController::class, 'index'])
        ->middleware('can:meta_ads.campaigns.view')
        ->name('meta_ads.campaigns.index');
    Route::middleware(['can:meta_ads.campaigns.manage', 'throttle:20,1'])->group(function (): void {
        Route::post('campaigns/{object}/status', [CampaignController::class, 'status'])->name('meta_ads.campaigns.status');
        Route::post('campaigns/{object}/budget', [CampaignController::class, 'budget'])->name('meta_ads.campaigns.budget');
    });

    // Authorisation and ad accounts (the setup wizard).
    Route::get('accounts', [AccountController::class, 'index'])
        ->middleware('can:meta_ads.accounts.view')
        ->name('meta_ads.accounts.index');
    Route::middleware('can:meta_ads.accounts.manage')->group(function (): void {
        Route::post('accounts/connect', [AccountController::class, 'connect'])
            ->middleware('throttle:10,1')
            ->name('meta_ads.accounts.connect');
        Route::get('accounts/callback', [AccountController::class, 'callback'])
            ->middleware('throttle:20,1')
            ->name('meta_ads.accounts.callback');
        Route::post('accounts/{account}/track', [AccountController::class, 'track'])->name('meta_ads.accounts.track');
        Route::delete('accounts/{account}/track', [AccountController::class, 'untrack'])->name('meta_ads.accounts.untrack');
        Route::post('accounts/{account}/sync', [AccountController::class, 'sync'])
            ->middleware('throttle:6,1')
            ->name('meta_ads.accounts.sync');
        Route::delete('connections/{connection}', [AccountController::class, 'disconnect'])->name('meta_ads.accounts.destroy');
    });

    Route::middleware('can:meta_ads.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('meta_ads.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('meta_ads.settings.update');
    });
});
