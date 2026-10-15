<?php

declare(strict_types=1);

use App\Modules\Telephony\Http\Controllers\CallController;
use App\Modules\Telephony\Http\Controllers\DialerController;
use App\Modules\Telephony\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('telephony')->name('telephony.')->group(function (): void {
    Route::middleware('can:telephony.calls.make')->group(function (): void {
        Route::get('dialer', [DialerController::class, 'show'])->name('dialer');
        Route::get('softphone/credentials', [DialerController::class, 'credentials'])->middleware('throttle:30,1')->name('softphone.credentials');
        Route::post('calls', [DialerController::class, 'start'])->middleware('throttle:60,1')->name('calls.start');
        Route::post('calls/{call}/status', [DialerController::class, 'report'])->middleware('throttle:120,1')->name('calls.report');
        Route::post('bridge', [DialerController::class, 'bridge'])
            ->middleware('throttle:'.(int) config('telephony.bridge_per_minute', 10).',1')
            ->name('bridge');
    });

    Route::get('calls', [CallController::class, 'index'])->middleware('can:telephony.calls.view')->name('calls.index');

    Route::middleware('can:telephony.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/webhook-secret', [SettingsController::class, 'rotateWebhookSecret'])->name('settings.webhook-secret');
        Route::post('settings/extensions', [SettingsController::class, 'storeExtension'])->name('settings.extensions.store');
        Route::put('settings/extensions/{extension}', [SettingsController::class, 'updateExtension'])->name('settings.extensions.update');
        Route::delete('settings/extensions/{extension}', [SettingsController::class, 'destroyExtension'])->name('settings.extensions.destroy');
    });
});
