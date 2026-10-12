<?php

declare(strict_types=1);

use App\Modules\Talent\Http\Controllers\AnalyticsController;
use App\Modules\Talent\Http\Controllers\ApplicationController;
use App\Modules\Talent\Http\Controllers\CandidateController;
use App\Modules\Talent\Http\Controllers\ChecklistController;
use App\Modules\Talent\Http\Controllers\CourseController;
use App\Modules\Talent\Http\Controllers\EnrolmentController;
use App\Modules\Talent\Http\Controllers\GoalController;
use App\Modules\Talent\Http\Controllers\InterviewController;
use App\Modules\Talent\Http\Controllers\MeController;
use App\Modules\Talent\Http\Controllers\OpeningController;
use App\Modules\Talent\Http\Controllers\ProbationController;
use App\Modules\Talent\Http\Controllers\ReviewController;
use App\Modules\Talent\Http\Controllers\ReviewCycleController;
use App\Modules\Talent\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('talent')->group(function (): void {
    // Self-service.
    Route::get('me', [MeController::class, 'show'])->middleware('can:talent.self.view')->name('talent.me');

    // Job openings. `openings/create` is declared before `{opening}` so it is not read as an id.
    Route::get('openings', [OpeningController::class, 'index'])->middleware('can:talent.openings.view')->name('talent.openings.index');
    Route::get('openings/create', [OpeningController::class, 'create'])->middleware('can:talent.openings.create')->name('talent.openings.create');
    Route::post('openings', [OpeningController::class, 'store'])->middleware('can:talent.openings.create')->name('talent.openings.store');
    Route::get('openings/{opening}', [OpeningController::class, 'show'])->middleware('can:talent.openings.view')->name('talent.openings.show');
    Route::put('openings/{opening}', [OpeningController::class, 'update'])->middleware('can:talent.openings.manage')->name('talent.openings.update');
    Route::post('openings/{opening}/status', [OpeningController::class, 'status'])->middleware('can:talent.openings.manage')->name('talent.openings.status');
    Route::post('openings/{opening}/applications', [OpeningController::class, 'apply'])->middleware('can:talent.applications.manage')->name('talent.openings.applications.store');

    // Candidates (sensitive).
    Route::get('candidates', [CandidateController::class, 'index'])->middleware('can:talent.candidates.view')->name('talent.candidates.index');
    Route::get('candidates/lookup', [CandidateController::class, 'lookup'])->middleware('can:talent.candidates.view')->name('talent.candidates.lookup');
    Route::post('candidates', [CandidateController::class, 'store'])->middleware('can:talent.candidates.manage')->name('talent.candidates.store');
    Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->middleware('can:talent.candidates.view')->name('talent.candidates.show');
    Route::put('candidates/{candidate}', [CandidateController::class, 'update'])->middleware('can:talent.candidates.manage')->name('talent.candidates.update');
    Route::post('candidates/{candidate}/anonymize', [CandidateController::class, 'anonymize'])->middleware('can:talent.candidates.manage')->name('talent.candidates.anonymize');
    Route::post('candidates/{candidate}/notes', [CandidateController::class, 'storeNote'])->middleware('can:talent.candidates.manage')->name('talent.candidates.notes.store');
    Route::post('candidates/{candidate}/documents', [CandidateController::class, 'storeDocument'])
        ->middleware(['can:talent.candidates.manage', 'throttle:30,1'])->name('talent.candidates.documents.store');
    Route::get('candidates/{candidate}/documents/{document}', [CandidateController::class, 'downloadDocument'])
        ->middleware('can:talent.candidates.view')->name('talent.candidates.documents.download');
    Route::delete('candidates/{candidate}/documents/{document}', [CandidateController::class, 'destroyDocument'])
        ->middleware('can:talent.candidates.manage')->name('talent.candidates.documents.destroy');

    // Applications, offers and the hire.
    Route::get('applications', [ApplicationController::class, 'index'])->middleware('can:talent.applications.view')->name('talent.applications.index');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->middleware('can:talent.applications.view')->name('talent.applications.show');
    Route::middleware('can:talent.applications.manage')->group(function (): void {
        Route::post('applications/{application}/move', [ApplicationController::class, 'move'])->name('talent.applications.move');
        Route::post('applications/{application}/reject', [ApplicationController::class, 'reject'])->name('talent.applications.reject');
        Route::post('applications/{application}/withdraw', [ApplicationController::class, 'withdraw'])->name('talent.applications.withdraw');
        Route::post('applications/{application}/reopen', [ApplicationController::class, 'reopen'])->name('talent.applications.reopen');
        Route::post('applications/{application}/notes', [ApplicationController::class, 'storeNote'])->name('talent.applications.notes.store');
    });
    Route::post('applications/{application}/hire', [ApplicationController::class, 'hire'])->middleware('can:talent.applications.hire')->name('talent.applications.hire');
    Route::middleware('can:talent.offers.manage')->group(function (): void {
        Route::post('applications/{application}/offers', [ApplicationController::class, 'storeOffer'])->name('talent.applications.offers.store');
        Route::put('offers/{offer}', [ApplicationController::class, 'updateOffer'])->name('talent.offers.update');
        Route::post('offers/{offer}/status', [ApplicationController::class, 'offerStatus'])->name('talent.offers.status');
    });

    // Interviews. A panelist reaches theirs through the panel (and `talent.interviews.view`).
    Route::get('interviews', [InterviewController::class, 'index'])->middleware('can:talent.interviews.view')->name('talent.interviews.index');
    Route::get('interviews/{interview}', [InterviewController::class, 'show'])->middleware('can:talent.interviews.view')->name('talent.interviews.show');
    Route::middleware('can:talent.interviews.manage')->group(function (): void {
        Route::post('applications/{application}/interviews', [InterviewController::class, 'store'])->name('talent.interviews.store');
        Route::put('interviews/{interview}', [InterviewController::class, 'update'])->name('talent.interviews.update');
        Route::post('interviews/{interview}/cancel', [InterviewController::class, 'cancel'])->name('talent.interviews.cancel');
        Route::post('interviews/{interview}/complete', [InterviewController::class, 'complete'])->name('talent.interviews.complete');
        Route::post('interviews/{interview}/no-show', [InterviewController::class, 'noShow'])->name('talent.interviews.no-show');
    });
    Route::post('interviews/{interview}/scorecard', [InterviewController::class, 'scorecard'])
        ->middleware('can:talent.interviews.feedback')->name('talent.interviews.scorecard');

    // Onboarding and offboarding.
    Route::get('checklists', [ChecklistController::class, 'index'])->middleware('can:talent.onboarding.view')->name('talent.checklists.index');
    Route::get('checklists/{checklist}', [ChecklistController::class, 'show'])->middleware('can:talent.onboarding.view')->name('talent.checklists.show');
    Route::get('tasks', [ChecklistController::class, 'tasks'])->middleware('can:talent.tasks.complete')->name('talent.tasks.index');
    Route::middleware('can:talent.onboarding.manage')->group(function (): void {
        Route::post('checklists', [ChecklistController::class, 'store'])->name('talent.checklists.store');
        Route::post('checklists/{checklist}/cancel', [ChecklistController::class, 'cancel'])->name('talent.checklists.cancel');
        Route::post('checklists/{checklist}/tasks', [ChecklistController::class, 'storeTask'])->name('talent.checklists.tasks.store');
        Route::post('checklist-tasks/{task}/skip', [ChecklistController::class, 'skipTask'])->name('talent.tasks.skip');
    });
    Route::post('checklist-tasks/{task}/complete', [ChecklistController::class, 'completeTask'])->middleware('can:talent.tasks.complete')->name('talent.tasks.complete');
    Route::post('checklist-tasks/{task}/reopen', [ChecklistController::class, 'reopenTask'])->middleware('can:talent.tasks.complete')->name('talent.tasks.reopen');

    // Probation.
    Route::get('probations', [ProbationController::class, 'index'])->middleware('can:talent.probation.view')->name('talent.probations.index');
    Route::get('probations/{probation}', [ProbationController::class, 'show'])->middleware('can:talent.probation.view')->name('talent.probations.show');
    Route::post('probations/{probation}/decisions', [ProbationController::class, 'decide'])->middleware('can:talent.probation.manage')->name('talent.probations.decisions.store');

    // Performance: cycles and forms.
    Route::middleware('can:talent.cycles.manage')->group(function (): void {
        Route::get('cycles', [ReviewCycleController::class, 'index'])->name('talent.cycles.index');
        Route::post('cycles', [ReviewCycleController::class, 'store'])->name('talent.cycles.store');
        Route::get('cycles/{cycle}', [ReviewCycleController::class, 'show'])->name('talent.cycles.show');
        Route::put('cycles/{cycle}', [ReviewCycleController::class, 'update'])->name('talent.cycles.update');
        Route::post('cycles/{cycle}/launch', [ReviewCycleController::class, 'launch'])->name('talent.cycles.launch');
        Route::post('cycles/{cycle}/calibration', [ReviewCycleController::class, 'startCalibration'])->name('talent.cycles.calibration');
        Route::post('cycles/{cycle}/close', [ReviewCycleController::class, 'close'])->name('talent.cycles.close');
        Route::post('review-forms', [ReviewCycleController::class, 'storeForm'])->name('talent.forms.store');
        Route::put('review-forms/{form}', [ReviewCycleController::class, 'updateForm'])->name('talent.forms.update');
        Route::post('reviews/{review}/reviewer', [ReviewController::class, 'assignReviewer'])->name('talent.reviews.reviewer');
    });

    // Performance: reviews.
    Route::get('reviews', [ReviewController::class, 'index'])->middleware('can:talent.reviews.view')->name('talent.reviews.index');
    Route::get('reviews/{review}', [ReviewController::class, 'show'])->middleware('can:talent.reviews.view')->name('talent.reviews.show');
    Route::post('reviews/{review}/acknowledge', [ReviewController::class, 'acknowledge'])->middleware('can:talent.reviews.view')->name('talent.reviews.acknowledge');
    Route::post('reviews/{review}/self', [ReviewController::class, 'submitSelf'])->middleware('can:talent.reviews.submit')->name('talent.reviews.self');
    Route::post('reviews/{review}/manager', [ReviewController::class, 'submitManager'])->middleware('can:talent.reviews.submit')->name('talent.reviews.manager');
    Route::post('reviews/{review}/calibrate', [ReviewController::class, 'calibrate'])->middleware('can:talent.reviews.calibrate')->name('talent.reviews.calibrate');

    // Goals and KPIs.
    Route::get('goals', [GoalController::class, 'index'])->middleware('can:talent.goals.view')->name('talent.goals.index');
    Route::get('goals/{goal}', [GoalController::class, 'show'])->middleware('can:talent.goals.view')->name('talent.goals.show');
    Route::middleware('can:talent.goals.manage')->group(function (): void {
        Route::post('goals', [GoalController::class, 'store'])->name('talent.goals.store');
        Route::put('goals/{goal}', [GoalController::class, 'update'])->name('talent.goals.update');
        Route::post('goals/{goal}/progress', [GoalController::class, 'progress'])->name('talent.goals.progress');
        Route::post('goals/{goal}/status', [GoalController::class, 'status'])->name('talent.goals.status');
    });

    // Training.
    Route::get('courses', [CourseController::class, 'index'])->middleware('can:talent.courses.view')->name('talent.courses.index');
    Route::get('courses/{course}', [CourseController::class, 'show'])->middleware('can:talent.courses.view')->name('talent.courses.show');
    Route::middleware('can:talent.courses.manage')->group(function (): void {
        Route::post('courses', [CourseController::class, 'store'])->name('talent.courses.store');
        Route::put('courses/{course}', [CourseController::class, 'update'])->name('talent.courses.update');
        Route::post('courses/{course}/sessions', [CourseController::class, 'storeSession'])->name('talent.courses.sessions.store');
        Route::put('training-sessions/{session}', [CourseController::class, 'updateSession'])->name('talent.sessions.update');
    });
    Route::get('enrolments', [EnrolmentController::class, 'index'])->middleware('can:talent.enrolments.view')->name('talent.enrolments.index');
    Route::middleware('can:talent.enrolments.manage')->group(function (): void {
        Route::post('enrolments', [EnrolmentController::class, 'store'])->name('talent.enrolments.store');
        Route::post('enrolments/{enrolment}/complete', [EnrolmentController::class, 'complete'])->name('talent.enrolments.complete');
        Route::post('enrolments/{enrolment}/fail', [EnrolmentController::class, 'fail'])->name('talent.enrolments.fail');
        Route::post('enrolments/{enrolment}/cancel', [EnrolmentController::class, 'cancel'])->name('talent.enrolments.cancel');
    });

    // Analytics.
    Route::get('analytics', [AnalyticsController::class, 'index'])->middleware('can:talent.analytics.view')->name('talent.analytics.index');

    // Settings: retention, reminders, pipelines, rejection reasons, checklist templates.
    Route::middleware('can:talent.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('talent.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('talent.settings.update');
        Route::post('settings/pipelines', [SettingsController::class, 'storePipeline'])->name('talent.settings.pipelines.store');
        Route::put('settings/pipelines/{pipeline}', [SettingsController::class, 'updatePipeline'])->name('talent.settings.pipelines.update');
        Route::post('settings/pipelines/{pipeline}/stages', [SettingsController::class, 'storeStage'])->name('talent.settings.stages.store');
        Route::put('settings/pipelines/{pipeline}/stages/{stage}', [SettingsController::class, 'updateStage'])->name('talent.settings.stages.update');
        Route::post('settings/rejection-reasons', [SettingsController::class, 'storeReason'])->name('talent.settings.rejection-reasons.store');
        Route::put('settings/rejection-reasons/{reason}', [SettingsController::class, 'updateReason'])->name('talent.settings.rejection-reasons.update');
        Route::post('settings/templates', [SettingsController::class, 'storeTemplate'])->name('talent.settings.templates.store');
        Route::put('settings/templates/{template}', [SettingsController::class, 'updateTemplate'])->name('talent.settings.templates.update');
    });
});
