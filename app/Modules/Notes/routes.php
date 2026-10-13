<?php

declare(strict_types=1);

use App\Modules\Notes\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('notes')->name('notes.')->group(function (): void {
    Route::get('/', [NoteController::class, 'index'])->middleware('can:notes.notes.view')->name('index');

    Route::middleware('can:notes.notes.manage')->group(function (): void {
        Route::post('/', [NoteController::class, 'store'])->name('store');
        Route::put('{note}', [NoteController::class, 'update'])->name('update');
        Route::delete('{note}', [NoteController::class, 'destroy'])->name('destroy');
    });
});
