<?php

declare(strict_types=1);

use App\Modules\Invoicing\Http\Controllers\BillableTimeController;
use App\Modules\Invoicing\Http\Controllers\CreditNoteController;
use App\Modules\Invoicing\Http\Controllers\InvoiceController;
use App\Modules\Invoicing\Http\Controllers\PaymentController;
use App\Modules\Invoicing\Http\Controllers\RecurringController;
use App\Modules\Invoicing\Http\Controllers\ReportController;
use App\Modules\Invoicing\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('invoicing')->group(function (): void {
    Route::middleware('can:invoicing.invoices.view')->group(function (): void {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoicing.invoices.index');
    });

    // Declared before the `{invoice}` routes so that `create` is not read as an id.
    Route::get('invoices/create', [InvoiceController::class, 'create'])
        ->middleware('can:invoicing.invoices.edit')
        ->name('invoicing.invoices.create');

    Route::middleware('can:invoicing.invoices.edit')->group(function (): void {
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoicing.invoices.store');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoicing.invoices.update');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoicing.invoices.destroy');
        Route::get('billable-time', [BillableTimeController::class, 'index'])->name('invoicing.billable-time.index');
        Route::post('billable-time/invoice', [BillableTimeController::class, 'store'])->name('invoicing.billable-time.store');
    });

    Route::middleware('can:invoicing.invoices.view')->group(function (): void {
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoicing.invoices.show');
        Route::get('invoices/{invoice}/document', [InvoiceController::class, 'document'])->name('invoicing.invoices.document');
        Route::get('invoices/{invoice}/credit-notes/{creditNote}/document', [CreditNoteController::class, 'document'])
            ->name('invoicing.invoices.credit-notes.document');
    });

    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
        ->middleware('can:invoicing.invoices.issue')
        ->name('invoicing.invoices.issue');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->middleware(['can:invoicing.invoices.send', 'throttle:30,1'])
        ->name('invoicing.invoices.send');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
        ->middleware('can:invoicing.invoices.void')
        ->name('invoicing.invoices.void');

    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])
        ->middleware('can:invoicing.payments.record')
        ->name('invoicing.invoices.payments.store');
    Route::post('invoices/{invoice}/payments/{payment}/reverse', [PaymentController::class, 'reverse'])
        ->middleware('can:invoicing.payments.reverse')
        ->name('invoicing.invoices.payments.reverse');
    Route::post('invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store'])
        ->middleware('can:invoicing.credit_notes.issue')
        ->name('invoicing.invoices.credit-notes.store');

    Route::get('recurring', [RecurringController::class, 'index'])
        ->middleware('can:invoicing.recurring.view')
        ->name('invoicing.recurring.index');
    Route::middleware('can:invoicing.recurring.manage')->group(function (): void {
        Route::post('recurring', [RecurringController::class, 'store'])->name('invoicing.recurring.store');
        Route::put('recurring/{template}', [RecurringController::class, 'update'])->name('invoicing.recurring.update');
        Route::patch('recurring/{template}/status', [RecurringController::class, 'status'])->name('invoicing.recurring.status');
    });

    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('can:invoicing.reports.view')
        ->name('invoicing.reports.index');

    Route::middleware('can:invoicing.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('invoicing.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('invoicing.settings.update');
        Route::post('settings/tax-rates', [SettingsController::class, 'storeTaxRate'])->name('invoicing.settings.tax-rates.store');
        Route::put('settings/tax-rates/{taxRate}', [SettingsController::class, 'updateTaxRate'])->name('invoicing.settings.tax-rates.update');
    });
});
