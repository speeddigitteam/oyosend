<?php

declare(strict_types=1);

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Talent\Actions\ApplicationManager;
use App\Modules\Talent\Actions\GoalManager;
use App\Modules\Talent\Actions\OfferManager;
use App\Modules\Talent\Actions\RecruitmentCatalogue;
use App\Modules\Talent\Actions\TrainingManager;
use App\Modules\Talent\Enums\AssigneeRole;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Enums\ChecklistStatus;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\AutomationActionReceipt;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\ChecklistTemplate;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Pipeline;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\RejectionReason;
use App\Modules\Talent\Models\Reminder;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewForm;
use App\Modules\Talent\Models\TalentSetting;
use App\Modules\Talent\Models\TrainingSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithHrm;
use Tests\Support\InteractsWithTalent;

uses(InteractsWithHrm::class, InteractsWithTalent::class);

$opening = function (): JobOpening {
    $pipeline = app(RecruitmentCatalogue::class)->defaultPipeline();

    return JobOpening::query()->create(['pipeline_id' => $pipeline->id, 'title' => 'Role '.Str::random(4), 'headcount' => 1, 'status' => OpeningStatus::Draft]);
};
$candidate = fn (): Candidate => Candidate::query()->create(['first_name' => 'Iso', 'email' => Str::random(6).'@example.test']);
$employeeId = fn (): string => (string) Str::ulid();

it('isolates the recruitment catalogue and records between tenants', function () use ($opening, $candidate) {
    $this->assertTenantIsolated(TalentSetting::class, fn () => TalentSetting::query()->create([]));
    $this->assertTenantIsolated(Pipeline::class, fn () => Pipeline::query()->create(['name' => 'P'.Str::random(5)]));
    $this->assertTenantIsolated(RejectionReason::class, fn () => RejectionReason::query()->create(['name' => 'R'.Str::random(5)]));
    $this->assertTenantIsolated(JobOpening::class, fn () => $opening());
    $this->assertTenantIsolated(Candidate::class, fn () => $candidate());
    $open = function () use ($opening): JobOpening {
        $job = $opening();
        $job->status = OpeningStatus::Open;
        $job->save();

        return $job;
    };
    $this->assertTenantIsolated(Application::class, fn () => app(ApplicationManager::class)->apply($open(), $candidate(), [], null));
    $this->assertTenantIsolated(Offer::class, function () use ($open, $candidate) {
        $application = app(ApplicationManager::class)->apply($open(), $candidate(), [], null);

        return app(OfferManager::class)->create($application, ['amount' => 100], null);
    });
});

it('isolates employee-bound Talent records between tenants', function () use ($employeeId) {
    $this->assertTenantIsolated(Probation::class, fn () => Probation::query()->create([
        'employee_id' => $employeeId(), 'started_on' => '2026-01-01', 'ends_on' => '2026-04-01', 'status' => ProbationStatus::InProgress,
    ]));
    $this->assertTenantIsolated(ChecklistTemplate::class, fn () => ChecklistTemplate::query()->create(['name' => 'T'.Str::random(5), 'kind' => ChecklistKind::Onboarding]));
    $this->assertTenantIsolated(Checklist::class, fn () => Checklist::query()->create([
        'employee_id' => $employeeId(), 'kind' => ChecklistKind::Onboarding, 'name' => 'C', 'anchor_date' => '2026-01-01',
        'status' => ChecklistStatus::Open, 'source_key' => 'k:'.Str::random(8),
    ]));
    $this->assertTenantIsolated(ChecklistTask::class, function () use ($employeeId) {
        $checklist = Checklist::query()->create([
            'employee_id' => $employeeId(), 'kind' => ChecklistKind::Offboarding, 'name' => 'C', 'anchor_date' => '2026-01-01',
            'status' => ChecklistStatus::Open, 'source_key' => 'k:'.Str::random(8),
        ]);

        return ChecklistTask::query()->create(['checklist_id' => $checklist->id, 'title' => 'T', 'assignee_role' => AssigneeRole::Hr]);
    });
    $this->assertTenantIsolated(Goal::class, fn () => Goal::query()->create([
        'employee_id' => $employeeId(), 'title' => 'G', 'target_value' => 5, 'status' => GoalStatus::Active,
    ]));
    $this->assertTenantIsolated(Course::class, fn () => Course::query()->create(['title' => 'C'.Str::random(5)]));
    $this->assertTenantIsolated(Enrolment::class, function () use ($employeeId) {
        $course = Course::query()->create(['title' => 'C'.Str::random(5)]);

        return Enrolment::query()->create([
            'course_id' => $course->id, 'employee_id' => $employeeId(), 'status' => EnrolmentStatus::Enrolled, 'enrolled_on' => '2026-01-01', 'open_marker' => 1,
        ]);
    });
    $this->assertTenantIsolated(TrainingSession::class, function () {
        $course = Course::query()->create(['title' => 'C'.Str::random(5)]);

        return TrainingSession::query()->create(['course_id' => $course->id, 'starts_at' => now()->addDay(), 'timezone' => 'UTC']);
    });
    $this->assertTenantIsolated(Reminder::class, fn () => Reminder::query()->create([
        'kind' => 'probation', 'subject_id' => (string) Str::ulid(), 'reminder_key' => 'r:'.Str::random(10),
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'talent.checklist.start', 'idempotency_key' => Str::random(10), 'output' => [],
    ]));
});

it('isolates review forms, cycles and reviews between tenants', function () use ($employeeId) {
    $this->assertTenantIsolated(ReviewForm::class, fn () => ReviewForm::query()->create(['name' => 'F'.Str::random(5), 'scale_max' => 5]));
    $this->assertTenantIsolated(ReviewCycle::class, function () {
        $form = ReviewForm::query()->create(['name' => 'F'.Str::random(5), 'scale_max' => 5]);

        return ReviewCycle::query()->create([
            'name' => 'C', 'form_id' => $form->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => CycleStatus::Draft,
        ]);
    });
    $this->assertTenantIsolated(Review::class, function () use ($employeeId) {
        $form = ReviewForm::query()->create(['name' => 'F'.Str::random(5), 'scale_max' => 5]);
        $cycle = ReviewCycle::query()->create([
            'name' => 'C', 'form_id' => $form->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => CycleStatus::Active,
        ]);

        return Review::query()->create(['cycle_id' => $cycle->id, 'employee_id' => $employeeId(), 'status' => ReviewStatus::PendingSelf]);
    });
});

it('has unique indexes that lead with the tenant: the same names can exist in two workspaces', function () {
    $a = $this->talentTenant('talent-iso-a');
    $b = $this->talentTenant('talent-iso-b');

    foreach ([$a, $b] as $tenant) {
        $this->inTenant($tenant, function (): void {
            Course::query()->create(['title' => 'Safety']);
            Pipeline::query()->create(['name' => 'Engineering']);
            RejectionReason::query()->create(['name' => 'Salary']);
        });
    }

    $this->inTenant($a, function (): void {
        expect(fn () => Course::query()->create(['title' => 'Safety']))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('answers 404 for another workspace\'s records by id on every id route', function () {
    $home = $this->talentTenant('talent-iso-home');
    $opening = $this->talentOpening($home);
    $candidate = $this->talentCandidate($home);
    $application = $this->talentApplication($home, $opening, $candidate);
    $employee = $this->hire($home, ['first_name' => 'Home']);
    $course = $this->inTenant($home, fn (): Course => app(TrainingManager::class)->saveCourse(null, ['title' => 'Safety']));
    $cycle = $this->talentCycle($home);
    $goal = $this->inTenant($home, fn (): Goal => app(GoalManager::class)->create(
        app(EmployeeDirectory::class)->find($employee->id), ['title' => 'G', 'target_value' => 5], null,
    ));
    $intruder = $this->talentTenant('talent-iso-intruder');

    $this->assertNotFoundFromOtherTenant('GET', "/talent/openings/{$opening->id}", $home, [], $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/talent/candidates/{$candidate->id}", $home, [], $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/talent/applications/{$application->id}", $home, [], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', "/talent/applications/{$application->id}/hire", $home, [], $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/talent/courses/{$course->id}", $home, [], $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/talent/cycles/{$cycle->id}", $home, [], $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/talent/goals/{$goal->id}", $home, [], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', "/talent/goals/{$goal->id}/progress", $home, ['value' => 1], $intruder);
});
