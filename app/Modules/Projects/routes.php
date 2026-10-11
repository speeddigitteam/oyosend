<?php

declare(strict_types=1);

use App\Modules\Projects\Http\Controllers\ApprovalController;
use App\Modules\Projects\Http\Controllers\ClientPortalController;
use App\Modules\Projects\Http\Controllers\CollaborationController;
use App\Modules\Projects\Http\Controllers\FinanceController;
use App\Modules\Projects\Http\Controllers\PlanningController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\ReportController;
use App\Modules\Projects\Http\Controllers\SettingsController;
use App\Modules\Projects\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->group(function (): void {
    Route::get('/projects', [ProjectController::class, 'index'])->middleware('can:projects.projects.view')->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('can:projects.projects.edit')->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->middleware('can:projects.projects.view')->name('projects.show');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->middleware('can:projects.projects.edit')->name('projects.update');
    Route::patch('/projects/{project}/status', [ProjectController::class, 'status'])->middleware('can:projects.projects.edit')->name('projects.status');
    Route::post('/projects/{project}/members', [ProjectController::class, 'addMember'])->middleware('can:projects.projects.manage_members')->name('projects.members.store');
    Route::delete('/projects/{project}/members/{membership}', [ProjectController::class, 'removeMember'])->middleware('can:projects.projects.manage_members')->name('projects.members.destroy');

    Route::post('/projects/{project}/tasks', [TaskController::class, 'store'])->middleware('can:projects.tasks.edit')->name('projects.tasks.store');
    Route::put('/projects/{project}/tasks/{task}', [TaskController::class, 'update'])->middleware('can:projects.tasks.edit')->name('projects.tasks.update');
    Route::patch('/projects/{project}/tasks/{task}/status', [TaskController::class, 'status'])->middleware('can:projects.tasks.edit')->name('projects.tasks.status');
    Route::put('/projects/{project}/tasks/{task}/assignees', [TaskController::class, 'assign'])->middleware('can:projects.tasks.assign')->name('projects.tasks.assign');
    Route::post('/projects/{project}/tasks/{task}/watchers', [TaskController::class, 'watch'])->middleware('can:projects.tasks.view')->name('projects.tasks.watch');
    Route::delete('/projects/{project}/tasks/{task}/watchers', [TaskController::class, 'unwatch'])->middleware('can:projects.tasks.view')->name('projects.tasks.unwatch');
    Route::post('/projects/{project}/tasks/{task}/comments', [CollaborationController::class, 'comment'])->middleware('can:projects.tasks.comment')->name('projects.tasks.comments.store');
    Route::post('/projects/{project}/tasks/{task}/checklists', [CollaborationController::class, 'addChecklist'])->middleware('can:projects.tasks.edit')->name('projects.tasks.checklists.store');
    Route::patch('/projects/{project}/tasks/{task}/checklists/{checklist}', [CollaborationController::class, 'updateChecklist'])->middleware('can:projects.tasks.edit')->name('projects.tasks.checklists.update');
    Route::post('/projects/{project}/tasks/{task}/dependencies', [CollaborationController::class, 'dependency'])->middleware('can:projects.tasks.edit')->name('projects.tasks.dependencies.store');

    Route::post('/projects/{project}/milestones', [PlanningController::class, 'storeMilestone'])->middleware('can:projects.projects.edit')->name('projects.milestones.store');
    Route::patch('/projects/{project}/milestones/{milestone}/status', [PlanningController::class, 'milestoneStatus'])->middleware('can:projects.projects.edit')->name('projects.milestones.status');
    Route::post('/projects/{project}/sprints', [PlanningController::class, 'storeSprint'])->middleware('can:projects.projects.edit')->name('projects.sprints.store');
    Route::patch('/projects/{project}/sprints/{sprint}/status', [PlanningController::class, 'sprintStatus'])->middleware('can:projects.projects.edit')->name('projects.sprints.status');

    Route::post('/projects/{project}/attachments', [CollaborationController::class, 'attach'])->middleware('can:projects.projects.view')->name('projects.attachments.store');
    Route::get('/projects/{project}/attachments/{attachment}', [CollaborationController::class, 'download'])->middleware('can:projects.projects.view')->name('projects.attachments.download');
    Route::delete('/projects/{project}/attachments/{attachment}', [CollaborationController::class, 'destroy'])->middleware('can:projects.projects.view')->name('projects.attachments.destroy');

    Route::get('/project-time', [FinanceController::class, 'time'])->middleware('can:projects.time.view')->name('projects.time.index');
    Route::get('/project-task-options', [FinanceController::class, 'taskOptions'])->name('projects.task-options.index');
    Route::post('/project-time', [FinanceController::class, 'storeTime'])->middleware('can:projects.time.log')->name('projects.time.store');
    Route::patch('/project-time/{timeEntry}/submit', [FinanceController::class, 'submitTime'])->middleware('can:projects.time.log')->name('projects.time.submit');
    Route::patch('/project-time/{timeEntry}/approve', [FinanceController::class, 'approveTime'])->middleware('can:projects.time.approve')->name('projects.time.approve');

    Route::get('/project-expenses', [FinanceController::class, 'expenses'])->middleware('can:projects.expenses.view')->name('projects.expenses.index');
    Route::post('/project-expenses', [FinanceController::class, 'storeExpense'])->middleware('can:projects.expenses.manage')->name('projects.expenses.store');
    Route::patch('/project-expenses/{expense}/submit', [FinanceController::class, 'submitExpense'])->middleware('can:projects.expenses.manage')->name('projects.expenses.submit');
    Route::patch('/project-expenses/{expense}/approve', [FinanceController::class, 'approveExpense'])->middleware('can:projects.expenses.approve')->name('projects.expenses.approve');

    Route::get('/project-approvals', [ApprovalController::class, 'index'])->name('projects.approvals.index');
    Route::post('/project-approvals', [ApprovalController::class, 'store'])->middleware('can:projects.approvals.request')->name('projects.approvals.store');
    Route::patch('/project-approvals/{approval}/decision', [ApprovalController::class, 'decide'])->middleware('can:projects.approvals.decide')->name('projects.approvals.decide');
    Route::patch('/project-approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->name('projects.approvals.cancel');

    Route::post('/projects/{project}/client-access', [ClientPortalController::class, 'grant'])->middleware('can:projects.projects.manage_members')->name('projects.client-access.store');
    Route::delete('/projects/{project}/client-access/{clientAccess}', [ClientPortalController::class, 'revoke'])->middleware('can:projects.projects.manage_members')->name('projects.client-access.destroy');

    Route::get('/project-reports', [ReportController::class, 'index'])->middleware('can:projects.reports.view')->name('projects.reports.index');
    Route::get('/project-settings', [SettingsController::class, 'index'])->middleware('can:projects.settings.manage')->name('projects.settings.index');
    Route::post('/project-settings/categories', [SettingsController::class, 'category'])->middleware('can:projects.settings.manage')->name('projects.settings.categories.store');
    Route::post('/project-settings/templates', [SettingsController::class, 'template'])->middleware('can:projects.settings.manage')->name('projects.settings.templates.store');
});

Route::middleware(['subscribed', 'throttle:60,1'])->group(function (): void {
    Route::get('/project-portal/{token}', [ClientPortalController::class, 'show'])->where('token', '[a-f0-9]{64}')->name('projects.portal.show');
    Route::post('/project-portal/{token}/tasks/{task}/comments', [ClientPortalController::class, 'comment'])->middleware('throttle:10,1')->where('token', '[a-f0-9]{64}')->name('projects.portal.comments.store');
});
