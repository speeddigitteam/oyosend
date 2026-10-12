<?php

declare(strict_types=1);

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Events\EmployeeCreated;
use App\Modules\Talent\Actions\ChecklistManager;
use App\Modules\Talent\Actions\ProbationManager;
use App\Modules\Talent\Actions\ReminderProcessor;
use App\Modules\Talent\Actions\TalentSettings;
use App\Modules\Talent\Actions\TrainingManager;
use App\Modules\Talent\Automation\Actions\StartChecklistAction;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Enums\ChecklistStatus;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Enums\TaskStatus;
use App\Modules\Talent\Events\ChecklistCompleted;
use App\Modules\Talent\Events\ProbationDecisionRecorded;
use App\Modules\Talent\Events\ProbationReviewDue;
use App\Modules\Talent\Listeners\SyncHrmEmployeeEvents;
use App\Modules\Talent\Models\AutomationActionReceipt;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\Reminder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithHrm;
use Tests\Support\InteractsWithTalent;

uses(InteractsWithHrm::class, InteractsWithTalent::class);

beforeEach(function () {
    $this->tenant = $this->talentTenant('talent-onb');
    $this->hrmAt('2026-03-02 10:00:00');
});

it('starts the default onboarding checklist once when an employee is created', function () {
    $this->talentTemplate($this->tenant);
    $boss = $this->hire($this->tenant, ['first_name' => 'Boss', 'joined_on' => '2025-01-01']);
    $this->inTenant($this->tenant, fn () => Checklist::query()->delete());

    $employee = $this->hire($this->tenant, ['first_name' => 'New', 'manager_id' => $boss->id, 'joined_on' => '2026-03-02']);

    $this->inTenant($this->tenant, function () use ($employee, $boss): void {
        $checklist = Checklist::query()->where('employee_id', $employee->id)->sole();
        $tasks = $checklist->tasks()->get()->keyBy('title');

        expect($checklist->kind)->toBe(ChecklistKind::Onboarding)
            ->and($checklist->anchor_date->toDateString())->toBe('2026-03-02')
            ->and($checklist->source_key)->toBe('hrm.employee.created:'.$employee->id)
            ->and($tasks)->toHaveCount(3)
            ->and($tasks['Prepare laptop']->assignee_employee_id)->toBeNull()
            ->and($tasks['Prepare laptop']->due_on->toDateString())->toBe('2026-02-28')
            ->and($tasks['Welcome meeting']->assignee_employee_id)->toBe($boss->id)
            ->and($tasks['Read the handbook']->assignee_employee_id)->toBe($employee->id)
            ->and($tasks['Read the handbook']->due_on->toDateString())->toBe('2026-03-07');

        // The same event delivered again (a retry, a second worker) creates nothing.
        $event = new EmployeeCreated(['employee_id' => $employee->id, 'number' => $employee->employee_number, 'name' => 'New']);
        app(SyncHrmEmployeeEvents::class)->handle($event);
        app(SyncHrmEmployeeEvents::class)->handle($event);

        expect(Checklist::query()->where('employee_id', $employee->id)->count())->toBe(1)
            ->and(ChecklistTask::query()->where('checklist_id', $checklist->id)->count())->toBe(3);
    });
});

it('does nothing for a workspace that is not entitled to Talent, and without a template', function () {
    $plain = $this->createTenant('talent-onb-plain');
    $this->entitle($plain, 'hrm');
    $this->inTenant($plain, fn () => app(ChecklistManager::class)->saveTemplate(null, ['name' => 'T', 'kind' => 'onboarding', 'is_default' => true, 'tasks' => [['title' => 'x']]]));
    $this->hire($plain, ['first_name' => 'Nobody']);
    $this->inTenant($plain, fn () => expect(Checklist::query()->count())->toBe(0));

    // Entitled, but no active template: no checklist (and no failure).
    $this->hire($this->tenant, ['first_name' => 'Plain']);
    $this->inTenant($this->tenant, fn () => expect(Checklist::query()->count())->toBe(0));
});

it('runs offboarding on termination, ends probation and cancels open training', function () {
    $this->talentTemplate($this->tenant);
    $this->talentTemplate($this->tenant, 'offboarding', [['title' => 'Return laptop', 'assignee_role' => 'hr', 'due_offset_days' => 0]]);
    $employee = $this->hire($this->tenant, ['first_name' => 'Leaver', 'status' => 'probation', 'joined_on' => '2026-01-05']);

    $this->inTenant($this->tenant, function () use ($employee): void {
        $course = app(TrainingManager::class)->saveCourse(null, ['title' => 'Safety']);
        $snapshot = app(EmployeeDirectory::class)->find($employee->id);
        app(TrainingManager::class)->enrol($course, $snapshot, null, null);

        expect(Probation::query()->where('employee_id', $employee->id)->sole()->status)->toBe(ProbationStatus::InProgress);

        app(EmployeeManager::class)->changeStatus($employee, EmployeeStatus::Terminated, [
            'reason' => 'Resigned', 'termination_type' => 'resignation', 'effective_on' => '2026-03-02', 'last_working_on' => '2026-03-20',
        ]);

        $off = Checklist::query()->where('employee_id', $employee->id)->where('kind', ChecklistKind::Offboarding)->sole();

        expect($off->anchor_date->toDateString())->toBe('2026-03-20')
            ->and($off->tasks()->sole()->due_on->toDateString())->toBe('2026-03-20')
            ->and(Probation::query()->where('employee_id', $employee->id)->sole()->status)->toBe(ProbationStatus::Ended)
            ->and(Enrolment::query()->where('employee_id', $employee->id)->sole()->status)->toBe(EnrolmentStatus::Cancelled);
    });
});

it('completes a checklist when its last task is done or skipped, and reopens it with a task', function () {
    Event::fake([ChecklistCompleted::class]);
    $this->talentTemplate($this->tenant, 'onboarding', [
        ['title' => 'One', 'assignee_role' => 'hr'], ['title' => 'Two', 'assignee_role' => 'hr'],
    ]);
    $employee = $this->hire($this->tenant);

    $this->inTenant($this->tenant, function () use ($employee): void {
        $checklist = Checklist::query()->where('employee_id', $employee->id)->sole();
        $manager = app(ChecklistManager::class);
        [$one, $two] = $checklist->tasks()->get()->all();

        $manager->completeTask($one, null);
        expect($checklist->refresh()->status)->toBe(ChecklistStatus::Open);

        $manager->skipTask($two, null, 'Not needed');
        expect($checklist->refresh()->status)->toBe(ChecklistStatus::Completed)
            ->and($checklist->completed_at)->not->toBeNull();

        $manager->addTask($checklist, ['title' => 'Three', 'assignee_role' => 'employee']);
        expect($checklist->refresh()->status)->toBe(ChecklistStatus::Open);

        $manager->reopenTask($one->refresh(), null);
        expect($one->refresh()->status)->toBe(TaskStatus::Pending);
    });

    Event::assertDispatchedTimes(ChecklistCompleted::class, 1);
});

it('starts a checklist from an automation action with a durable receipt', function () {
    $this->talentTemplate($this->tenant);
    $employee = $this->hire($this->tenant);
    $this->inTenant($this->tenant, fn () => Checklist::query()->delete());

    $this->inTenant($this->tenant, function () use ($employee): void {
        $action = app(StartChecklistAction::class);
        $first = $action->execute(['employee_id' => $employee->id, 'kind' => 'onboarding'], 'wf-1');
        $second = $action->execute(['employee_id' => $employee->id, 'kind' => 'onboarding'], 'wf-1');
        $third = $action->execute(['employee_id' => $employee->id, 'kind' => 'onboarding'], 'wf-2');

        expect($first['checklist_id'])->not->toBeNull()
            ->and($second)->toBe($first)
            ->and($third['checklist_id'])->not->toBe($first['checklist_id'])
            ->and(Checklist::query()->count())->toBe(2)
            ->and(AutomationActionReceipt::query()->count())->toBe(2);
    });
});

it('tracks probation from the employee record, with the default length when HRM has no end date', function () {
    $dated = $this->hire($this->tenant, ['first_name' => 'Dated', 'status' => 'probation', 'joined_on' => '2026-02-01', 'probation_ends_on' => '2026-04-15']);
    $undated = $this->hire($this->tenant, ['first_name' => 'Undated', 'status' => 'probation', 'joined_on' => '2026-02-01']);
    $active = $this->hire($this->tenant, ['first_name' => 'Active']);

    $this->inTenant($this->tenant, function () use ($dated, $undated, $active): void {
        $one = Probation::query()->where('employee_id', $dated->id)->sole();
        $two = Probation::query()->where('employee_id', $undated->id)->sole();

        expect($one->ends_on->toDateString())->toBe('2026-04-15')
            ->and($one->observed_ends_on->toDateString())->toBe('2026-04-15')
            ->and($two->ends_on->toDateString())->toBe('2026-05-02')   // 2026-02-01 + 90 days
            ->and($two->observed_ends_on)->toBeNull()
            ->and(Probation::query()->where('employee_id', $active->id)->exists())->toBeFalse();
    });
});

it('reminds once per end date before probation ends and stops after a final decision', function () {
    Event::fake([ProbationReviewDue::class, ProbationDecisionRecorded::class]);
    $employee = $this->hire($this->tenant, ['first_name' => 'Probie', 'status' => 'probation', 'joined_on' => '2026-02-01', 'probation_ends_on' => '2026-04-15']);

    $run = fn (): array => $this->inTenant($this->tenant, fn () => app(ReminderProcessor::class)->run());

    // 14 days before the end by default: April 1.
    $this->hrmAt('2026-03-31 09:00:00');
    expect($run()['probation'])->toBe(0);

    $this->hrmAt('2026-04-02 09:00:00');
    expect($run()['probation'])->toBe(1)
        ->and($run()['probation'])->toBe(0);   // idempotent: a rerun announces nothing

    Event::assertDispatchedTimes(ProbationReviewDue::class, 1);
    Event::assertDispatched(ProbationReviewDue::class, fn (ProbationReviewDue $event): bool => $event->payload()['employee_id'] === $employee->id
        && $event->payload()['ends_on'] === '2026-04-15' && $event->payload()['days_left'] === 13);

    // An extension changes the end date, which is a new fact to remind about when it nears.
    $this->inTenant($this->tenant, function () use ($employee): void {
        $probation = Probation::query()->where('employee_id', $employee->id)->sole();
        app(ProbationManager::class)->recordDecision($probation, ['decision' => 'extend', 'extend_until' => '2026-05-15', 'note' => 'Needs more time'], null);

        expect($probation->refresh()->ends_on->toDateString())->toBe('2026-05-15');
    });

    $this->hrmAt('2026-05-10 09:00:00');
    expect($run()['probation'])->toBe(1)->and($run()['probation'])->toBe(0);

    $this->inTenant($this->tenant, function () use ($employee): void {
        $probation = Probation::query()->where('employee_id', $employee->id)->sole();
        app(ProbationManager::class)->recordDecision($probation, ['decision' => 'confirm'], null);
        expect(fn () => app(ProbationManager::class)->recordDecision($probation, ['decision' => 'extend'], null))->toThrow(ValidationException::class);
    });

    $this->hrmAt('2026-05-14 09:00:00');
    expect($run()['probation'])->toBe(0);
    $this->inTenant($this->tenant, fn () => expect(Reminder::query()->where('kind', 'probation')->count())->toBe(2));
    Event::assertDispatched(ProbationDecisionRecorded::class, 2);
});

it('closes the probation as confirmed when HRM moves the employee to active', function () {
    $employee = $this->hire($this->tenant, ['first_name' => 'Probie', 'status' => 'probation', 'joined_on' => '2026-02-01']);

    $this->inTenant($this->tenant, function () use ($employee): void {
        app(EmployeeManager::class)->changeStatus($employee, EmployeeStatus::Active, ['effective_on' => '2026-03-02']);

        $probation = Probation::query()->where('employee_id', $employee->id)->sole();
        expect($probation->status)->toBe(ProbationStatus::Confirmed)
            ->and($probation->open_marker)->toBeNull()
            ->and($probation->closed_on->toDateString())->toBe('2026-03-02');
    });
});

it('follows the probation end date HRM changes but not an extension recorded here', function () {
    $employee = $this->hire($this->tenant, ['first_name' => 'Probie', 'status' => 'probation', 'joined_on' => '2026-02-01', 'probation_ends_on' => '2026-04-15']);

    $this->inTenant($this->tenant, function () use ($employee): void {
        $probation = Probation::query()->where('employee_id', $employee->id)->sole();
        app(ProbationManager::class)->recordDecision($probation, ['decision' => 'extend', 'extend_until' => '2026-05-15'], null);

        // A directory read with an unchanged HRM date keeps the extension.
        $snapshot = app(EmployeeDirectory::class)->find($employee->id);
        app(ProbationManager::class)->sync($probation->refresh(), $snapshot);
        expect($probation->refresh()->ends_on->toDateString())->toBe('2026-05-15');

        // HRM changes its own date: that replaces ours.
        app(EmployeeManager::class)->update($employee, ['probation_ends_on' => '2026-06-30']);
        expect($probation->refresh()->ends_on->toDateString())->toBe('2026-06-30')
            ->and(app(TalentSettings::class)->daysUntil('2026-06-30'))->toBe(120);
    });
});

it('keeps the owner of employee records in step when HRM links an account', function () {
    $this->talentTemplate($this->tenant);
    $employee = $this->hire($this->tenant, ['first_name' => 'Later']);
    $user = $this->addMember($this->tenant);
    $membership = $this->membershipOf($this->tenant, $user);

    $this->inTenant($this->tenant, function () use ($employee, $membership): void {
        expect(Checklist::query()->where('employee_id', $employee->id)->sole()->owner_id)->toBeNull();

        app(EmployeeManager::class)->update($employee, ['membership_id' => $membership->id]);

        expect(Checklist::query()->where('employee_id', $employee->id)->sole()->owner_id)->toBe($membership->id);
    });
});
