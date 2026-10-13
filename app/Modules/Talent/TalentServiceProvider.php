<?php

declare(strict_types=1);

namespace App\Modules\Talent;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Talent\Actions\CandidateManager;
use App\Modules\Talent\Actions\OpeningManager;
use App\Modules\Talent\Automation\Actions\EnrolInCourseAction;
use App\Modules\Talent\Automation\Actions\StartChecklistAction;
use App\Modules\Talent\Automation\Triggers\ApplicationStageChangedTrigger;
use App\Modules\Talent\Automation\Triggers\CandidateHiredTrigger;
use App\Modules\Talent\Automation\Triggers\CertificateExpiredTrigger;
use App\Modules\Talent\Automation\Triggers\CertificateExpiringTrigger;
use App\Modules\Talent\Automation\Triggers\ChecklistCompletedTrigger;
use App\Modules\Talent\Automation\Triggers\InterviewScheduledTrigger;
use App\Modules\Talent\Automation\Triggers\ProbationReviewDueTrigger;
use App\Modules\Talent\Automation\Triggers\ReviewCalibratedTrigger;
use App\Modules\Talent\Automation\Triggers\TrainingCompletedTrigger;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Jobs\EnforceRetention;
use App\Modules\Talent\Jobs\SendReminders;
use App\Modules\Talent\Listeners\SyncHrmEmployeeEvents;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\ApplicationHistory;
use App\Modules\Talent\Models\AutomationActionReceipt;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateDocument;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\ChecklistTemplate;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\GoalUpdate;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\InterviewPanelist;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Note;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Pipeline;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\ProbationReview;
use App\Modules\Talent\Models\RejectionReason;
use App\Modules\Talent\Models\Reminder;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewForm;
use App\Modules\Talent\Models\ReviewFormItem;
use App\Modules\Talent\Models\ReviewRating;
use App\Modules\Talent\Models\Scorecard;
use App\Modules\Talent\Models\TalentSetting;
use App\Modules\Talent\Models\TemplateTask;
use App\Modules\Talent\Models\TrainingSession;
use App\Modules\Talent\Reporting\ReportProviders;
use App\Modules\Talent\Support\TalentActor;
use App\Modules\Talent\Widgets\TalentOverviewWidget;
use App\Modules\Talent\Widgets\TalentSelfWidget;
use Illuminate\Support\Facades\Event;

/**
 * Talent & Performance (category HR & Payroll): recruitment, onboarding and offboarding, probation,
 * performance reviews and goals, training, and HR analytics.
 *
 * It depends on `hrm` and reads employees only through the `App\Core\Workforce` contracts and HRM's
 * domain events; no HRM model is imported. Candidates are Talent's own records and never a `User`.
 */
final class TalentServiceProvider extends ModuleServiceProvider
{
    /** HRM's employee events, referenced by class name only (the classes are not imported). */
    private const array HRM_EVENTS = [
        'App\\Modules\\Hrm\\Events\\EmployeeCreated',
        'App\\Modules\\Hrm\\Events\\EmployeeUpdated',
        'App\\Modules\\Hrm\\Events\\EmployeeTerminated',
    ];

    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        $this->app->scoped(TalentActor::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'talent',
            name: 'Talent & Performance',
            version: '1.0.0',
            dependencies: ['core', 'hrm'],
            permissions: [
                new PermissionDefinition('talent.self.view', 'talent_self'),
                new PermissionDefinition('talent.openings.view', 'talent_recruitment', scopable: true),
                new PermissionDefinition('talent.openings.create', 'talent_recruitment'),
                new PermissionDefinition('talent.openings.manage', 'talent_recruitment', scopable: true),
                new PermissionDefinition('talent.candidates.view', 'talent_recruitment', scopable: true, sensitive: true),
                new PermissionDefinition('talent.candidates.manage', 'talent_recruitment', scopable: true, sensitive: true),
                new PermissionDefinition('talent.applications.view', 'talent_recruitment', scopable: true, sensitive: true),
                new PermissionDefinition('talent.applications.manage', 'talent_recruitment', scopable: true),
                new PermissionDefinition('talent.applications.hire', 'talent_recruitment', scopable: true, sensitive: true),
                new PermissionDefinition('talent.interviews.view', 'talent_recruitment', scopable: true),
                new PermissionDefinition('talent.interviews.manage', 'talent_recruitment', scopable: true),
                new PermissionDefinition('talent.interviews.feedback', 'talent_recruitment', sensitive: true),
                new PermissionDefinition('talent.offers.manage', 'talent_recruitment', scopable: true, sensitive: true),
                new PermissionDefinition('talent.onboarding.view', 'talent_onboarding', scopable: true),
                new PermissionDefinition('talent.onboarding.manage', 'talent_onboarding', scopable: true),
                new PermissionDefinition('talent.tasks.complete', 'talent_onboarding'),
                new PermissionDefinition('talent.probation.view', 'talent_onboarding', scopable: true),
                new PermissionDefinition('talent.probation.manage', 'talent_onboarding', scopable: true, sensitive: true),
                new PermissionDefinition('talent.cycles.manage', 'talent_performance', sensitive: true),
                new PermissionDefinition('talent.reviews.view', 'talent_performance', scopable: true, sensitive: true),
                new PermissionDefinition('talent.reviews.submit', 'talent_performance'),
                new PermissionDefinition('talent.reviews.calibrate', 'talent_performance', scopable: true, sensitive: true),
                new PermissionDefinition('talent.goals.view', 'talent_performance', scopable: true),
                new PermissionDefinition('talent.goals.manage', 'talent_performance', scopable: true),
                new PermissionDefinition('talent.courses.view', 'talent_training'),
                new PermissionDefinition('talent.courses.manage', 'talent_training'),
                new PermissionDefinition('talent.enrolments.view', 'talent_training', scopable: true),
                new PermissionDefinition('talent.enrolments.manage', 'talent_training', scopable: true),
                new PermissionDefinition('talent.analytics.view', 'talent_analytics', sensitive: true),
                new PermissionDefinition('talent.settings.manage', 'talent_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('talentMe', 'talent.me', 'user-round-check', 'talent.self.view', 'apps', 40, 'talent.me'),
                new NavigationItem('talentOpenings', 'talent.openings.index', 'briefcase', 'talent.openings.view', 'apps', 41, 'talent.openings.*'),
                new NavigationItem('talentApplications', 'talent.applications.index', 'kanban', 'talent.applications.view', 'apps', 42, 'talent.applications.*'),
                new NavigationItem('talentCandidates', 'talent.candidates.index', 'user-search', 'talent.candidates.view', 'apps', 43, 'talent.candidates.*'),
                new NavigationItem('talentInterviews', 'talent.interviews.index', 'calendar-clock', 'talent.interviews.view', 'apps', 44, 'talent.interviews.*'),
                new NavigationItem('talentChecklists', 'talent.checklists.index', 'list-checks', 'talent.onboarding.view', 'apps', 45, 'talent.checklists.*'),
                new NavigationItem('talentTasks', 'talent.tasks.index', 'square-check-big', 'talent.tasks.complete', 'apps', 46, 'talent.tasks.*'),
                new NavigationItem('talentProbations', 'talent.probations.index', 'hourglass', 'talent.probation.view', 'apps', 47, 'talent.probations.*'),
                new NavigationItem('talentReviews', 'talent.reviews.index', 'star', 'talent.reviews.view', 'apps', 48, 'talent.reviews.*'),
                new NavigationItem('talentCycles', 'talent.cycles.index', 'refresh-cw', 'talent.cycles.manage', 'apps', 49, 'talent.cycles.*'),
                new NavigationItem('talentGoals', 'talent.goals.index', 'target', 'talent.goals.view', 'apps', 50, 'talent.goals.*'),
                new NavigationItem('talentCourses', 'talent.courses.index', 'graduation-cap', 'talent.courses.view', 'apps', 51, 'talent.courses.*'),
                new NavigationItem('talentEnrolments', 'talent.enrolments.index', 'book-open-check', 'talent.enrolments.view', 'apps', 52, 'talent.enrolments.*'),
                new NavigationItem('talentAnalytics', 'talent.analytics.index', 'chart-no-axes-combined', 'talent.analytics.view', 'apps', 53, 'talent.analytics.*'),
                new NavigationItem('talentSettings', 'talent.settings.index', 'settings', 'talent.settings.manage', 'manage', 39, 'talent.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('talent.self', 'TalentSelfWidget', TalentSelfWidget::class, 'talent.self.view', span: 4, order: 17),
                new WidgetDefinition('talent.overview', 'TalentOverviewWidget', TalentOverviewWidget::class, 'talent.analytics.view', span: 8, order: 18),
            ],
            triggers: [
                CandidateHiredTrigger::class,
                ApplicationStageChangedTrigger::class,
                InterviewScheduledTrigger::class,
                ChecklistCompletedTrigger::class,
                ProbationReviewDueTrigger::class,
                ReviewCalibratedTrigger::class,
                TrainingCompletedTrigger::class,
                CertificateExpiringTrigger::class,
                CertificateExpiredTrigger::class,
            ],
            actions: [StartChecklistAction::class, EnrolInCourseAction::class],
            morphMap: [
                'talent_setting' => TalentSetting::class,
                'talent_pipeline' => Pipeline::class,
                'talent_pipeline_stage' => PipelineStage::class,
                'talent_rejection_reason' => RejectionReason::class,
                'talent_job_opening' => JobOpening::class,
                'talent_candidate' => Candidate::class,
                'talent_application' => Application::class,
                'talent_application_history' => ApplicationHistory::class,
                'talent_candidate_document' => CandidateDocument::class,
                'talent_note' => Note::class,
                'talent_interview' => Interview::class,
                'talent_interview_panelist' => InterviewPanelist::class,
                'talent_scorecard' => Scorecard::class,
                'talent_offer' => Offer::class,
                'talent_probation' => Probation::class,
                'talent_probation_review' => ProbationReview::class,
                'talent_checklist_template' => ChecklistTemplate::class,
                'talent_template_task' => TemplateTask::class,
                'talent_checklist' => Checklist::class,
                'talent_checklist_task' => ChecklistTask::class,
                'talent_review_form' => ReviewForm::class,
                'talent_review_form_item' => ReviewFormItem::class,
                'talent_review_cycle' => ReviewCycle::class,
                'talent_review' => Review::class,
                'talent_review_rating' => ReviewRating::class,
                'talent_goal' => Goal::class,
                'talent_goal_update' => GoalUpdate::class,
                'talent_course' => Course::class,
                'talent_training_session' => TrainingSession::class,
                'talent_enrolment' => Enrolment::class,
                'talent_reminder' => Reminder::class,
                'talent_automation_receipt' => AutomationActionReceipt::class,
            ],
            limits: [
                new LimitDefinition(
                    OpeningManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => JobOpening::query()->where('status', OpeningStatus::Open)->count(),
                ),
                new LimitDefinition(
                    CandidateManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => Candidate::query()->whereNull('anonymized_at')->count(),
                ),
            ],
            description: 'Recruitment pipeline and interviews, onboarding and offboarding checklists, probation tracking, performance reviews and goals, training and HR analytics.',
            category: AppCategory::HrmPayroll,
            icon: 'trending-up',
            featured: false,
            features: [
                'Job openings, candidate pipeline, interviews with panels and scorecards, offers',
                'Onboarding and offboarding checklists started from HRM events',
                'Probation tracking with review reminders',
                'Review cycles with weighted competencies, calibration and acknowledgement',
                'Goals and KPIs, training courses, sessions and certification expiry',
                'Hiring funnel, time to fill, headcount and attrition analytics',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Initial Talent & Performance release.',
            routes: [
                'talent.me',
                'talent.openings.index',
                'talent.openings.create',
                'talent.openings.store',
                'talent.openings.show',
                'talent.openings.update',
                'talent.openings.status',
                'talent.openings.applications.store',
                'talent.candidates.index',
                'talent.candidates.lookup',
                'talent.candidates.store',
                'talent.candidates.show',
                'talent.candidates.update',
                'talent.candidates.anonymize',
                'talent.candidates.notes.store',
                'talent.candidates.documents.store',
                'talent.candidates.documents.download',
                'talent.candidates.documents.destroy',
                'talent.applications.index',
                'talent.applications.show',
                'talent.applications.move',
                'talent.applications.reject',
                'talent.applications.withdraw',
                'talent.applications.reopen',
                'talent.applications.notes.store',
                'talent.applications.hire',
                'talent.applications.offers.store',
                'talent.offers.update',
                'talent.offers.status',
                'talent.interviews.index',
                'talent.interviews.show',
                'talent.interviews.store',
                'talent.interviews.update',
                'talent.interviews.cancel',
                'talent.interviews.complete',
                'talent.interviews.no-show',
                'talent.interviews.scorecard',
                'talent.checklists.index',
                'talent.checklists.show',
                'talent.tasks.index',
                'talent.checklists.store',
                'talent.checklists.cancel',
                'talent.checklists.tasks.store',
                'talent.tasks.skip',
                'talent.tasks.complete',
                'talent.tasks.reopen',
                'talent.probations.index',
                'talent.probations.show',
                'talent.probations.decisions.store',
                'talent.cycles.index',
                'talent.cycles.store',
                'talent.cycles.show',
                'talent.cycles.update',
                'talent.cycles.launch',
                'talent.cycles.calibration',
                'talent.cycles.close',
                'talent.forms.store',
                'talent.forms.update',
                'talent.reviews.reviewer',
                'talent.reviews.index',
                'talent.reviews.show',
                'talent.reviews.acknowledge',
                'talent.reviews.self',
                'talent.reviews.manager',
                'talent.reviews.calibrate',
                'talent.goals.index',
                'talent.goals.show',
                'talent.goals.store',
                'talent.goals.update',
                'talent.goals.progress',
                'talent.goals.status',
                'talent.courses.index',
                'talent.courses.show',
                'talent.courses.store',
                'talent.courses.update',
                'talent.courses.sessions.store',
                'talent.sessions.update',
                'talent.enrolments.index',
                'talent.enrolments.store',
                'talent.enrolments.complete',
                'talent.enrolments.fail',
                'talent.enrolments.cancel',
                'talent.analytics.index',
                'talent.settings.index',
                'talent.settings.update',
                'talent.settings.pipelines.store',
                'talent.settings.pipelines.update',
                'talent.settings.stages.store',
                'talent.settings.stages.update',
                'talent.settings.rejection-reasons.store',
                'talent.settings.rejection-reasons.update',
                'talent.settings.templates.store',
                'talent.settings.templates.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('talent.reminders', SendReminders::class, ScheduledJobFrequency::Daily),
                new ScheduledJobDefinition('talent.retention', EnforceRetention::class, ScheduledJobFrequency::Daily),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');

        foreach (self::HRM_EVENTS as $event) {
            Event::listen($event, [SyncHrmEmployeeEvents::class, 'handle']);
        }
    }
}
