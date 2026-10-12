<?php

declare(strict_types=1);

use App\Modules\Reports\Http\Controllers\ReportController;
use App\Modules\Reports\Http\Controllers\SavedReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('reports')->group(function (): void {
    Route::get('/', [ReportController::class, 'index'])->middleware('can:reports.reports.view')->name('reports.index');

    // `run` and `saved` are fixed prefixes, so a report key can never be read as a saved report id.
    Route::get('run/{provider}', [ReportController::class, 'run'])
        ->where('provider', '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*')
        ->middleware(['can:reports.reports.view', 'throttle:60,1'])->name('reports.run');
    Route::get('run/{provider}/export', [ReportController::class, 'export'])
        ->where('provider', '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*')
        ->middleware(['can:reports.reports.export', 'throttle:20,1'])->name('reports.export');

    Route::get('saved/{saved}', [SavedReportController::class, 'show'])
        ->middleware(['can:reports.reports.view', 'throttle:60,1'])->name('reports.saved.show');
    Route::get('saved/{saved}/export', [SavedReportController::class, 'export'])
        ->middleware(['can:reports.reports.export', 'throttle:20,1'])->name('reports.saved.export');

    Route::middleware('can:reports.saved.manage')->group(function (): void {
        Route::post('saved', [SavedReportController::class, 'store'])->name('reports.saved.store');
        Route::put('saved/{saved}', [SavedReportController::class, 'update'])->name('reports.saved.update');
        Route::delete('saved/{saved}', [SavedReportController::class, 'destroy'])->name('reports.saved.destroy');
    });
});
