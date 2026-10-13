<?php

declare(strict_types=1);

use App\Modules\Social\LeadAds\Http\LeadAdsController;
use Illuminate\Support\Facades\Route;

/*
| Facebook Lead Ads - workspace pages. Loaded with the Social routes (tenant, entitled:social).
| Viewing needs `social.leadads.view`, every change `social.leadads.manage`.
*/
Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('social/lead-ads')->group(function (): void {
    Route::get('/', [LeadAdsController::class, 'index'])
        ->middleware('can:social.leadads.view')
        ->name('social.leadads.index');

    Route::middleware('can:social.leadads.manage')->group(function (): void {
        Route::post('accounts/{account}/enable', [LeadAdsController::class, 'enable'])
            ->middleware('throttle:10,1')->name('social.leadads.enable');
        Route::post('accounts/{account}/disable', [LeadAdsController::class, 'disable'])
            ->middleware('throttle:10,1')->name('social.leadads.disable');
        Route::post('accounts/{account}/forms/refresh', [LeadAdsController::class, 'refreshForms'])
            ->middleware('throttle:10,1')->name('social.leadads.forms.refresh');
        Route::post('accounts/{account}/backfill', [LeadAdsController::class, 'backfill'])
            ->middleware('throttle:6,1')->name('social.leadads.backfill');
        Route::post('forms/{form}/switch', [LeadAdsController::class, 'switchForm'])->name('social.leadads.forms.switch');
        Route::put('forms/{form}/mapping', [LeadAdsController::class, 'saveMapping'])->name('social.leadads.forms.mapping');
        Route::post('forms/{form}/preview', [LeadAdsController::class, 'preview'])
            ->middleware('throttle:60,1')->name('social.leadads.forms.preview');
        Route::post('leads/{lead}/retry', [LeadAdsController::class, 'retry'])
            ->middleware('throttle:30,1')->name('social.leadads.leads.retry');
    });
});
