<?php

declare(strict_types=1);

use App\Modules\CRM\Http\Controllers\ContractController;
use App\Modules\CRM\Http\Controllers\CustomerLifecycleController;
use App\Modules\CRM\Http\Controllers\PriceListController;
use App\Modules\CRM\Http\Controllers\ProductController;
use App\Modules\CRM\Http\Controllers\QuoteController;
use App\Modules\CRM\Http\Controllers\RecordContentController;
use App\Modules\CRM\Http\Controllers\SalesOrderController;
use App\Modules\CRM\Http\Controllers\SalesReportController;
use App\Modules\CRM\Http\Controllers\SalesTargetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('crm/sales')->group(function (): void {
    Route::middleware('can:crm.products.view')->group(function (): void {
        Route::get('products', [ProductController::class, 'index'])->name('crm.sales.products.index');
        Route::get('price-lists', [PriceListController::class, 'index'])->name('crm.sales.price-lists.index');
    });
    Route::middleware('can:crm.products.manage')->group(function (): void {
        Route::post('products', [ProductController::class, 'store'])->name('crm.sales.products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('crm.sales.products.update');
        Route::post('price-lists', [PriceListController::class, 'store'])->name('crm.sales.price-lists.store');
        Route::put('price-lists/{priceList}', [PriceListController::class, 'update'])->name('crm.sales.price-lists.update');
        Route::post('price-lists/{priceList}/items', [PriceListController::class, 'storeItem'])->name('crm.sales.price-lists.items.store');
        Route::delete('price-lists/{priceList}/items/{priceListItem}', [PriceListController::class, 'destroyItem'])
            ->name('crm.sales.price-lists.items.destroy');
    });

    Route::middleware('can:crm.quotes.view')->group(function (): void {
        Route::get('quotes', [QuoteController::class, 'index'])->name('crm.sales.quotes.index');
        Route::get('quotes/{quote}', [QuoteController::class, 'show'])->name('crm.sales.quotes.show');
    });
    Route::middleware('can:crm.quotes.manage')->group(function (): void {
        Route::post('quotes', [QuoteController::class, 'store'])->name('crm.sales.quotes.store');
        Route::put('quotes/{quote}', [QuoteController::class, 'update'])->name('crm.sales.quotes.update');
        Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->name('crm.sales.quotes.send');
        Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject'])->name('crm.sales.quotes.reject');
        Route::post('quotes/{quote}/cancel', [QuoteController::class, 'cancel'])->name('crm.sales.quotes.cancel');
    });
    Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])
        ->middleware('can:crm.quotes.accept')
        ->name('crm.sales.quotes.accept');

    Route::middleware('can:crm.orders.view')->group(function (): void {
        Route::get('orders', [SalesOrderController::class, 'index'])->name('crm.sales.orders.index');
        Route::get('orders/{salesOrder}', [SalesOrderController::class, 'show'])->name('crm.sales.orders.show');
    });
    Route::patch('orders/{salesOrder}/status', [SalesOrderController::class, 'updateStatus'])
        ->middleware('can:crm.orders.manage')
        ->name('crm.sales.orders.status');

    Route::get('contracts', [ContractController::class, 'index'])
        ->middleware('can:crm.contracts.view')
        ->name('crm.sales.contracts.index');
    Route::middleware('can:crm.contracts.manage')->group(function (): void {
        Route::post('contracts', [ContractController::class, 'store'])->name('crm.sales.contracts.store');
        Route::put('contracts/{contract}', [ContractController::class, 'update'])->name('crm.sales.contracts.update');
        Route::patch('contracts/{contract}/status', [ContractController::class, 'updateStatus'])->name('crm.sales.contracts.status');
    });

    Route::get('targets', [SalesTargetController::class, 'index'])
        ->middleware('can:crm.targets.view')
        ->name('crm.sales.targets.index');
    Route::middleware('can:crm.targets.manage')->group(function (): void {
        Route::post('targets', [SalesTargetController::class, 'store'])->name('crm.sales.targets.store');
        Route::put('targets/{salesTarget}', [SalesTargetController::class, 'update'])->name('crm.sales.targets.update');
    });

    Route::middleware('can:crm.customers.view')->group(function (): void {
        Route::get('customers', [CustomerLifecycleController::class, 'index'])->name('crm.sales.customers.index');
        Route::get('customers/{customerProfile}', [CustomerLifecycleController::class, 'show'])->name('crm.sales.customers.show');
        Route::get('customers/{customerProfile}/attachments/{attachment}', [RecordContentController::class, 'downloadCustomer'])
            ->name('crm.sales.customers.attachments.download');
    });
    Route::middleware('can:crm.customers.manage')->group(function (): void {
        Route::patch('customers/{customerProfile}', [CustomerLifecycleController::class, 'update'])->name('crm.sales.customers.update');
        Route::post('customers/{customerProfile}/notes', [RecordContentController::class, 'customerNote'])->name('crm.sales.customers.notes.store');
        Route::put('customers/{customerProfile}/tags', [RecordContentController::class, 'customerTags'])->name('crm.sales.customers.tags.update');
        Route::put('customers/{customerProfile}/custom-values', [RecordContentController::class, 'customerCustomValues'])->name('crm.sales.customers.custom-values.update');
        Route::post('customers/{customerProfile}/attachments', [RecordContentController::class, 'customerAttachment'])
            ->middleware('throttle:20,1')->name('crm.sales.customers.attachments.store');
        Route::delete('customers/{customerProfile}/attachments/{attachment}', [RecordContentController::class, 'destroyCustomerAttachment'])
            ->name('crm.sales.customers.attachments.destroy');
    });

    Route::get('reports', [SalesReportController::class, 'index'])
        ->middleware('can:crm.reports.view')
        ->name('crm.sales.reports.index');
});
