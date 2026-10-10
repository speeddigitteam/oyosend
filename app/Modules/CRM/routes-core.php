<?php

declare(strict_types=1);

use App\Modules\CRM\Http\Controllers\ActivityController;
use App\Modules\CRM\Http\Controllers\CrmSettingsController;
use App\Modules\CRM\Http\Controllers\DealController;
use App\Modules\CRM\Http\Controllers\LeadController;
use App\Modules\CRM\Http\Controllers\RecordContentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('crm')->name('crm.')->group(function (): void {
    Route::middleware('can:crm.leads.view')->group(function (): void {
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::get('leads/{lead}/attachments/{attachment}', [RecordContentController::class, 'downloadLead'])->name('leads.attachments.download');
    });
    Route::middleware('can:crm.leads.edit')->group(function (): void {
        Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
        Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::post('leads/{lead}/notes', [RecordContentController::class, 'leadNote'])->name('leads.notes.store');
        Route::put('leads/{lead}/tags', [RecordContentController::class, 'leadTags'])->name('leads.tags.update');
        Route::put('leads/{lead}/custom-values', [RecordContentController::class, 'leadCustomValues'])->name('leads.custom-values.update');
        Route::post('leads/{lead}/attachments', [RecordContentController::class, 'leadAttachment'])->middleware('throttle:20,1')->name('leads.attachments.store');
        Route::delete('leads/{lead}/attachments/{attachment}', [RecordContentController::class, 'destroyLeadAttachment'])->name('leads.attachments.destroy');
    });
    Route::post('leads/{lead}/qualify', [LeadController::class, 'qualify'])->middleware('can:crm.leads.qualify')->name('leads.qualify');
    Route::put('leads/{lead}/assignment', [LeadController::class, 'assign'])->middleware('can:crm.leads.assign')->name('leads.assign');

    Route::middleware('can:crm.deals.view')->group(function (): void {
        Route::get('deals', [DealController::class, 'index'])->name('deals.index');
        Route::get('deals/{deal}', [DealController::class, 'show'])->name('deals.show');
        Route::get('deals/{deal}/attachments/{attachment}', [RecordContentController::class, 'downloadDeal'])->name('deals.attachments.download');
    });
    Route::middleware('can:crm.deals.edit')->group(function (): void {
        Route::post('deals', [DealController::class, 'store'])->name('deals.store');
        Route::put('deals/{deal}', [DealController::class, 'update'])->name('deals.update');
        Route::post('deals/{deal}/stage', [DealController::class, 'move'])->name('deals.stage');
        Route::post('deals/{deal}/notes', [RecordContentController::class, 'dealNote'])->name('deals.notes.store');
        Route::put('deals/{deal}/tags', [RecordContentController::class, 'dealTags'])->name('deals.tags.update');
        Route::put('deals/{deal}/custom-values', [RecordContentController::class, 'dealCustomValues'])->name('deals.custom-values.update');
        Route::post('deals/{deal}/attachments', [RecordContentController::class, 'dealAttachment'])->middleware('throttle:20,1')->name('deals.attachments.store');
        Route::delete('deals/{deal}/attachments/{attachment}', [RecordContentController::class, 'destroyDealAttachment'])->name('deals.attachments.destroy');
    });
    Route::put('deals/{deal}/assignment', [DealController::class, 'assign'])->middleware('can:crm.deals.assign')->name('deals.assign');

    Route::get('activities', [ActivityController::class, 'index'])->middleware('can:crm.activities.view')->name('activities.index');
    Route::middleware('can:crm.activities.edit')->group(function (): void {
        Route::post('activities', [ActivityController::class, 'store'])->name('activities.store');
        Route::put('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
        Route::post('activities/{activity}/complete', [ActivityController::class, 'complete'])->name('activities.complete');
        Route::post('activities/{activity}/cancel', [ActivityController::class, 'cancel'])->name('activities.cancel');
    });

    Route::middleware('can:crm.settings.manage')->prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', [CrmSettingsController::class, 'index'])->name('index');
        Route::post('pipelines', [CrmSettingsController::class, 'storePipeline'])->name('pipelines.store');
        Route::put('pipelines/{pipeline}', [CrmSettingsController::class, 'updatePipeline'])->name('pipelines.update');
        Route::post('pipelines/{pipeline}/stages', [CrmSettingsController::class, 'storeStage'])->name('stages.store');
        Route::post('sources', [CrmSettingsController::class, 'storeSource'])->name('sources.store');
        Route::post('tags', [CrmSettingsController::class, 'storeTag'])->name('tags.store');
        Route::post('custom-fields', [CrmSettingsController::class, 'storeCustomField'])->name('custom-fields.store');
        Route::post('segments', [CrmSettingsController::class, 'storeSegment'])->name('segments.store');
    });
});
