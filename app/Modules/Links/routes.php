<?php

declare(strict_types=1);

use App\Modules\Links\Http\Controllers\LinkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('links')->name('links.')->group(function (): void {
    Route::get('/', [LinkController::class, 'index'])->middleware('can:links.links.view')->name('index');

    Route::middleware('can:links.links.manage')->group(function (): void {
        Route::post('/', [LinkController::class, 'store'])->name('store');
        Route::put('{link}', [LinkController::class, 'update'])->name('update');
        Route::delete('{link}', [LinkController::class, 'destroy'])->name('destroy');
    });
});
