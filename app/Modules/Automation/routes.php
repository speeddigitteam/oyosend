<?php

declare(strict_types=1);

use App\Modules\Automation\Http\Controllers\ApprovalController;
use App\Modules\Automation\Http\Controllers\RunController;
use App\Modules\Automation\Http\Controllers\WebhookEndpointController;
use App\Modules\Automation\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('automation')->group(function (): void {
    // Workflows.
    Route::get('workflows', [WorkflowController::class, 'index'])->middleware('can:automation.workflows.view')->name('automation.workflows.index');
    Route::post('workflows', [WorkflowController::class, 'store'])->middleware('can:automation.workflows.manage')->name('automation.workflows.store');
    Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])->middleware('can:automation.workflows.view')->name('automation.workflows.show');
    Route::put('workflows/{workflow}', [WorkflowController::class, 'update'])->middleware('can:automation.workflows.manage')->name('automation.workflows.update');
    Route::get('workflows/{workflow}/editor', [WorkflowController::class, 'editor'])->middleware('can:automation.workflows.view')->name('automation.workflows.editor');
    Route::put('workflows/{workflow}/draft', [WorkflowController::class, 'saveDraft'])->middleware(['can:automation.workflows.manage', 'throttle:120,1'])->name('automation.workflows.draft');
    Route::post('workflows/{workflow}/validate', [WorkflowController::class, 'validateGraph'])->middleware(['can:automation.workflows.manage', 'throttle:120,1'])->name('automation.workflows.validate');
    Route::post('workflows/{workflow}/publish', [WorkflowController::class, 'publish'])->middleware('can:automation.workflows.publish')->name('automation.workflows.publish');
    Route::post('workflows/{workflow}/pause', [WorkflowController::class, 'pause'])->middleware('can:automation.workflows.publish')->name('automation.workflows.pause');
    Route::post('workflows/{workflow}/resume', [WorkflowController::class, 'resume'])->middleware('can:automation.workflows.publish')->name('automation.workflows.resume');
    Route::post('workflows/{workflow}/archive', [WorkflowController::class, 'archive'])->middleware('can:automation.workflows.publish')->name('automation.workflows.archive');
    Route::post('workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate'])->middleware('can:automation.workflows.manage')->name('automation.workflows.duplicate');
    Route::post('workflows/{workflow}/versions/{version}/restore', [WorkflowController::class, 'restore'])->middleware('can:automation.workflows.manage')->name('automation.workflows.versions.restore');
    Route::post('workflows/{workflow}/webhook/rotate', [WorkflowController::class, 'rotateWebhook'])->middleware('can:automation.webhooks.manage')->name('automation.workflows.webhook.rotate');
    Route::post('workflows/{workflow}/test', [WorkflowController::class, 'test'])->middleware(['can:automation.workflows.manage', 'throttle:30,1'])->name('automation.workflows.test');
    Route::post('workflows/{workflow}/run', [WorkflowController::class, 'run'])->middleware(['can:automation.runs.start', 'throttle:60,1'])->name('automation.workflows.run');

    // Runs.
    Route::get('runs', [RunController::class, 'index'])->middleware('can:automation.runs.view')->name('automation.runs.index');
    Route::get('runs/{run}', [RunController::class, 'show'])->middleware('can:automation.runs.view')->name('automation.runs.show');
    Route::post('runs/{run}/cancel', [RunController::class, 'cancel'])->middleware('can:automation.runs.manage')->name('automation.runs.cancel');
    Route::post('runs/{run}/retry', [RunController::class, 'retry'])->middleware('can:automation.runs.manage')->name('automation.runs.retry');

    // Approvals: the route only needs the decide permission; the manager applies who may decide which one.
    Route::get('approvals', [ApprovalController::class, 'index'])->middleware('can:automation.approvals.decide')->name('automation.approvals.index');
    Route::post('approvals/{approval}/decide', [ApprovalController::class, 'decide'])->middleware('can:automation.approvals.decide')->name('automation.approvals.decide');

    // Outbound webhook endpoints.
    Route::get('webhooks', [WebhookEndpointController::class, 'index'])->middleware('can:automation.webhooks.manage')->name('automation.webhooks.index');
    Route::post('webhooks', [WebhookEndpointController::class, 'store'])->middleware('can:automation.webhooks.manage')->name('automation.webhooks.store');
    Route::put('webhooks/{endpoint}', [WebhookEndpointController::class, 'update'])->middleware('can:automation.webhooks.manage')->name('automation.webhooks.update');
    Route::delete('webhooks/{endpoint}', [WebhookEndpointController::class, 'destroy'])->middleware('can:automation.webhooks.manage')->name('automation.webhooks.destroy');
});
