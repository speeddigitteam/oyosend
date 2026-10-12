<?php

declare(strict_types=1);

use App\Modules\Payroll\Http\Controllers\ItemController;
use App\Modules\Payroll\Http\Controllers\LoanController;
use App\Modules\Payroll\Http\Controllers\PaymentController;
use App\Modules\Payroll\Http\Controllers\PayslipController;
use App\Modules\Payroll\Http\Controllers\PeriodController;
use App\Modules\Payroll\Http\Controllers\ReportController;
use App\Modules\Payroll\Http\Controllers\RuleSetController;
use App\Modules\Payroll\Http\Controllers\RunController;
use App\Modules\Payroll\Http\Controllers\SalaryController;
use App\Modules\Payroll\Http\Controllers\SettingsController;
use App\Modules\Payroll\Http\Controllers\StructureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('payroll')->group(function (): void {
    // Payslips: scoped by `payroll.payslips.view` (an employee with the `own` scope sees only their own).
    Route::middleware('can:payroll.payslips.view')->group(function (): void {
        Route::get('payslips', [PayslipController::class, 'index'])->name('payroll.payslips.index');
        Route::get('payslips/{payslip}', [PayslipController::class, 'show'])->name('payroll.payslips.show');
        Route::get('payslips/{payslip}/document', [PayslipController::class, 'document'])->middleware('throttle:60,1')->name('payroll.payslips.document');
    });
    Route::post('payslips/{payslip}/payment', [PaymentController::class, 'store'])
        ->middleware(['can:payroll.payments.record', 'throttle:60,1'])->name('payroll.payments.store');

    // Pay periods.
    Route::get('periods', [PeriodController::class, 'index'])->middleware('can:payroll.periods.view')->name('payroll.periods.index');
    Route::get('periods/{period}', [PeriodController::class, 'show'])->middleware('can:payroll.periods.view')->name('payroll.periods.show');
    Route::middleware('can:payroll.periods.manage')->group(function (): void {
        Route::post('periods', [PeriodController::class, 'store'])->name('payroll.periods.store');
        Route::put('periods/{period}', [PeriodController::class, 'update'])->name('payroll.periods.update');
        Route::delete('periods/{period}', [PeriodController::class, 'destroy'])->name('payroll.periods.destroy');
        Route::post('periods/{period}/close', [PeriodController::class, 'close'])->name('payroll.periods.close');
    });

    // Runs.
    Route::get('runs', [RunController::class, 'index'])->middleware('can:payroll.runs.view')->name('payroll.runs.index');
    Route::get('runs/{run}', [RunController::class, 'show'])->middleware('can:payroll.runs.view')->name('payroll.runs.show');
    Route::middleware('can:payroll.runs.prepare')->group(function (): void {
        Route::post('periods/{period}/runs', [RunController::class, 'store'])->name('payroll.runs.store');
        Route::post('periods/{period}/adjustment-runs', [RunController::class, 'storeAdjustment'])->name('payroll.runs.adjustments.store');
        Route::post('runs/{run}/recalculate', [RunController::class, 'recalculate'])->name('payroll.runs.recalculate');
        Route::post('runs/{run}/submit', [RunController::class, 'submit'])->name('payroll.runs.submit');
        Route::delete('runs/{run}', [RunController::class, 'destroy'])->name('payroll.runs.destroy');
    });
    Route::middleware('can:payroll.runs.approve')->group(function (): void {
        Route::post('runs/{run}/approve', [RunController::class, 'approve'])->name('payroll.runs.approve');
        Route::post('runs/{run}/return', [RunController::class, 'sendBack'])->name('payroll.runs.return');
    });
    Route::post('runs/{run}/reverse', [RunController::class, 'reverse'])->middleware('can:payroll.runs.reverse')->name('payroll.runs.reverse');
    Route::post('runs/{run}/payments', [PaymentController::class, 'storeForRun'])
        ->middleware(['can:payroll.payments.record', 'throttle:20,1'])->name('payroll.runs.payments.store');
    Route::get('runs/{run}/payment-sheet', [ReportController::class, 'paymentSheet'])
        ->middleware(['can:payroll.payments.export', 'throttle:20,1'])->name('payroll.runs.payment-sheet');

    // Salary structures and salaries.
    Route::get('structures', [StructureController::class, 'index'])->middleware('can:payroll.structures.view')->name('payroll.structures.index');
    Route::get('structures/{structure}', [StructureController::class, 'show'])->middleware('can:payroll.structures.view')->name('payroll.structures.show');
    Route::middleware('can:payroll.structures.manage')->group(function (): void {
        Route::post('structures', [StructureController::class, 'store'])->name('payroll.structures.store');
        Route::put('structures/{structure}', [StructureController::class, 'update'])->name('payroll.structures.update');
        Route::post('structures/{structure}/components', [StructureController::class, 'storeComponent'])->name('payroll.structures.components.store');
        Route::put('structures/{structure}/components/{component}', [StructureController::class, 'updateComponent'])->name('payroll.structures.components.update');
        Route::delete('structures/{structure}/components/{component}', [StructureController::class, 'destroyComponent'])->name('payroll.structures.components.destroy');
    });

    Route::get('salaries', [SalaryController::class, 'index'])->middleware('can:payroll.salaries.view')->name('payroll.salaries.index');
    Route::get('salaries/{profile}', [SalaryController::class, 'show'])->middleware('can:payroll.salaries.view')->name('payroll.salaries.show');
    Route::middleware('can:payroll.salaries.manage')->group(function (): void {
        Route::post('salaries', [SalaryController::class, 'store'])->name('payroll.salaries.store');
        Route::post('salaries/{profile}/revisions', [SalaryController::class, 'revise'])->name('payroll.salaries.revisions.store');
        Route::put('salaries/{profile}/account', [SalaryController::class, 'updateAccount'])->name('payroll.salaries.account.update');
    });

    // One-off pay items.
    Route::get('items', [ItemController::class, 'index'])->middleware('can:payroll.items.view')->name('payroll.items.index');
    Route::middleware('can:payroll.items.manage')->group(function (): void {
        Route::post('items', [ItemController::class, 'store'])->name('payroll.items.store');
        Route::put('items/{item}', [ItemController::class, 'update'])->name('payroll.items.update');
        Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('payroll.items.destroy');
    });

    // Loans and advances.
    Route::get('loans', [LoanController::class, 'index'])->middleware('can:payroll.loans.view')->name('payroll.loans.index');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->middleware('can:payroll.loans.view')->name('payroll.loans.show');
    Route::post('loans', [LoanController::class, 'store'])->middleware('can:payroll.loans.manage')->name('payroll.loans.store');
    Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])->middleware('can:payroll.loans.manage')->name('payroll.loans.cancel');
    Route::post('loans/{loan}/decision', [LoanController::class, 'decision'])->middleware('can:payroll.loans.approve')->name('payroll.loans.decision');

    // Compliance rule sets.
    Route::get('rules', [RuleSetController::class, 'index'])->middleware('can:payroll.rules.view')->name('payroll.rules.index');
    Route::get('rules/{ruleSet}', [RuleSetController::class, 'show'])->middleware('can:payroll.rules.view')->name('payroll.rules.show');
    Route::post('rules', [RuleSetController::class, 'store'])->middleware('can:payroll.rules.manage')->name('payroll.rules.store');
    Route::put('rules/{ruleSet}', [RuleSetController::class, 'update'])->middleware('can:payroll.rules.manage')->name('payroll.rules.update');

    // Reports (`reports/export` is declared before nothing with an id, so there is no clash).
    Route::middleware('can:payroll.reports.view')->group(function (): void {
        Route::get('reports', [ReportController::class, 'index'])->name('payroll.reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->middleware('throttle:20,1')->name('payroll.reports.export');
    });

    Route::get('settings', [SettingsController::class, 'index'])->middleware('can:payroll.settings.manage')->name('payroll.settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->middleware('can:payroll.settings.manage')->name('payroll.settings.update');
});
