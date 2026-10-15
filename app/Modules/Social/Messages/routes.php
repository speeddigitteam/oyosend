<?php

declare(strict_types=1);

use App\Modules\Social\Messages\Http\DirectMessageController;
use Illuminate\Support\Facades\Route;

/*
| Messenger and Instagram direct messages (ADR 0031). Loaded through `loadTenantRoutes()` (workspace host, entitled to
| Social). The webhook is the audience comments' one callback (Comments/callbacks.php and centralCallbacks.php); there
| is no separate messaging callback. Record scope and the account allow-list are enforced in the controller.
*/
Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('social/messages')->group(function (): void {
    Route::get('/', [DirectMessageController::class, 'index'])->middleware('can:social.messages.view')->name('social.messages.index');

    // Turning capture on or off for a Page changes what the workspace receives from Meta: account managers only.
    Route::middleware('can:social.accounts.manage')->prefix('accounts/{account}')->where(['account' => '[0-9A-Za-z]{26}'])->group(function (): void {
        Route::post('enable', [DirectMessageController::class, 'enable'])->middleware('throttle:10,1')->name('social.messages.capture.enable');
        Route::post('disable', [DirectMessageController::class, 'disable'])->middleware('throttle:10,1')->name('social.messages.capture.disable');
    });

    Route::prefix('{conversation}')->where(['conversation' => '[0-9A-Za-z]{26}'])->group(function (): void {
        Route::post('read', [DirectMessageController::class, 'read'])->middleware(['can:social.messages.view', 'throttle:120,1'])->name('social.messages.read');
        Route::post('reply', [DirectMessageController::class, 'reply'])->middleware(['can:social.messages.reply', 'throttle:30,1'])->name('social.messages.reply');
        Route::put('status', [DirectMessageController::class, 'status'])->middleware('can:social.messages.reply')->name('social.messages.status');
        Route::put('assignee', [DirectMessageController::class, 'assign'])->middleware('can:social.messages.reply')->name('social.messages.assign');
    });
});
