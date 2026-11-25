<?php

declare(strict_types=1);

use App\Modules\Calendar\Http\Controllers\CalendarController;
use App\Modules\Calendar\Http\Controllers\CalendarsController;
use App\Modules\Calendar\Http\Controllers\EventController;
use App\Modules\Calendar\Http\Controllers\PreferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('calendar')->name('calendar.')->group(function (): void {
    Route::middleware('can:calendar.events.view')->group(function (): void {
        Route::get('/', [CalendarController::class, 'index'])->name('index');
        Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');
        Route::patch('calendars/{calendar}/visibility', [CalendarsController::class, 'toggle'])->name('calendars.toggle');
        Route::post('people', [CalendarsController::class, 'follow'])->name('people.store');
        Route::delete('people/{membership}', [CalendarsController::class, 'unfollow'])->name('people.destroy');
        Route::post('events/{event}/response', [EventController::class, 'respond'])->name('events.respond');
    });

    Route::middleware('can:calendar.events.manage')->group(function (): void {
        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::put('events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::patch('events/{event}/move', [EventController::class, 'move'])->name('events.move');
        Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
        Route::post('calendars', [CalendarsController::class, 'store'])->name('calendars.store');
        Route::put('calendars/{calendar}', [CalendarsController::class, 'update'])->name('calendars.update');
        Route::delete('calendars/{calendar}', [CalendarsController::class, 'destroy'])->name('calendars.destroy');
    });
});
