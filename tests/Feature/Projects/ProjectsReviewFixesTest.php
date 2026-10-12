<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RoleManager;
use App\Modules\Projects\Actions\ApprovalManager;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Actions\TimeExpenseManager;
use App\Modules\Projects\Automation\Actions\AssignTaskAction;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\TaskController;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use Illuminate\Validation\ValidationException;

it('stores a recurrence interval as an integer and re-arms a reminder when its time changes', function () {
    $tenant = $this->createTenant('projects-fix-recurrence');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    $project = $this->inTenant($tenant, fn () => app(ProjectManager::class)->create(['name' => 'Interval'], $owner, $owner));

    $this->actingAsMember($tenant, $ownerUser)
        ->post($this->tenantUrl($tenant, "/projects/{$project->id}/tasks"), [
            'title' => 'Every other week',
            'priority' => 'normal',
            'due_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'recurrence_rule' => ['frequency' => 'weekly', 'interval' => '2'],
        ])->assertRedirect()->assertSessionHasNoErrors();

    $this->inTenant($tenant, function () use ($project, $owner): void {
        $task = Task::query()->where('project_id', $project->id)->sole();

        expect($task->recurrence_rule['interval'])->toBe(2);

        $task->forceFill(['remind_at' => now()->subHour(), 'reminder_sent_at' => now()])->save();
        app(TaskManager::class)->update($task, ['remind_at' => now()->addDay()], $owner);

        expect($task->refresh()->reminder_sent_at)->toBeNull();
    });
});

it('keeps approving time and expenses behind their own permission, scope and a no-self-approval rule', function () {
    $tenant = $this->createTenant('projects-fix-approval');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    $decideOnly = $this->membershipOf($tenant, $this->addMember($tenant, $this->createRole($tenant, 'Decide only', ['projects.approvals.decide'])));
    $approverUser = $this->addMember($tenant, $this->createRole($tenant, 'Real approvers', ['projects.approvals.decide', 'projects.time.approve', 'projects.expenses.approve']));
    $approver = $this->membershipOf($tenant, $approverUser);
    $ownScopedRole = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $ownerUser,
        'Own-scope approvers',
        null,
        ['projects.approvals.decide', 'projects.time.approve'],
        ['projects.time.approve' => AccessScope::Own],
    ));
    $ownScoped = $this->membershipOf($tenant, $this->addMember($tenant, $ownScopedRole));

    $this->inTenant($tenant, function () use ($owner, $decideOnly, $approver, $ownScoped): void {
        $project = app(ProjectManager::class)->create(['name' => 'Approval fixes'], $owner, $owner);
        $finance = app(TimeExpenseManager::class);
        $approvals = app(ApprovalManager::class);
        $time = $finance->logTime($project, $approver, ['worked_on' => '2026-10-10', 'minutes' => 60], $approver);
        $finance->submitTime($time, $approver);

        // Holding only approvals.decide is not enough, nor is a narrower scope that misses the entry.
        expect(fn () => $approvals->request($project, ApprovalEntity::TimeEntry, $time->id, $owner, $decideOnly))->toThrow(ValidationException::class)
            ->and(fn () => $approvals->request($project, ApprovalEntity::TimeEntry, $time->id, $owner, $ownScoped))->toThrow(ValidationException::class)
            ->and(fn () => $finance->decideTime($time, true, $decideOnly))->toThrow(ValidationException::class)
            ->and(fn () => $finance->decideTime($time, true, $ownScoped))->toThrow(ValidationException::class)
            // Nobody decides their own entry, even with the permission.
            ->and(fn () => $finance->decideTime($time, true, $approver))->toThrow(ValidationException::class)
            ->and(fn () => $approvals->request($project, ApprovalEntity::TimeEntry, $time->id, $owner, $approver))->toThrow(ValidationException::class);

        expect($time->refresh()->status)->toBe(EntryStatus::Submitted);

        $expense = $finance->createExpense($project, $approver, ['description' => 'Taxi', 'incurred_on' => '2026-10-10', 'amount' => 500]);
        $finance->submitExpense($expense, $approver);

        expect(fn () => $finance->decideExpense($expense, true, $approver))->toThrow(ValidationException::class)
            ->and(fn () => $finance->decideExpense($expense, true, $decideOnly))->toThrow(ValidationException::class);

        $finance->decideExpense($expense, true, $owner);

        expect($expense->refresh()->status)->toBe(EntryStatus::Approved);
    });
});

it('rejects a time entry whose minutes disagree with its start and end', function () {
    $tenant = $this->createTenant('projects-fix-minutes');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $project = app(ProjectManager::class)->create(['name' => 'Minutes'], $owner, $owner);
        $finance = app(TimeExpenseManager::class);

        expect(fn () => $finance->logTime($project, $owner, ['worked_on' => '2026-10-10', 'started_at' => '2026-10-10 10:00:00', 'ended_at' => '2026-10-10 11:00:00', 'minutes' => 120]))
            ->toThrow(ValidationException::class);

        expect($finance->logTime($project, $owner, ['worked_on' => '2026-10-10', 'started_at' => '2026-10-10 10:00:00', 'ended_at' => '2026-10-10 11:00:00', 'minutes' => 60])->minutes)->toBe(60);
    });
});

it('does not expose raw activity metadata and requires project view for task mutations', function () {
    $tenant = $this->createTenant('projects-fix-task-scope');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    // May edit and view every task but has no project view at all.
    $editorUser = $this->addMember($tenant, $this->createRole($tenant, 'Task editors', [TaskController::VIEW, TaskController::EDIT, TaskController::COMMENT]));
    [$project, $task] = $this->inTenant($tenant, function () use ($owner): array {
        $project = app(ProjectManager::class)->create(['name' => 'Hidden project'], $owner, $owner);

        return [$project, app(TaskManager::class)->create($project, ['title' => 'Hidden task'], $owner, $owner)];
    });

    $this->actingAsMember($tenant, $editorUser)
        ->patch($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}/status"), ['status' => 'in_progress'])
        ->assertNotFound();
    $this->post($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}/comments"), ['body' => 'Hi'])->assertNotFound();
    $this->post($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}/watchers"))->assertNotFound();

    $this->actingAsMember($tenant, $ownerUser)
        ->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('activity')->missing('activity.0.metadata'));
});

it('hides dependency titles outside the viewer task scope', function () {
    $tenant = $this->createTenant('projects-fix-dependency-scope');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    $viewerRole = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $ownerUser,
        'Own task viewers',
        null,
        [ProjectController::VIEW, TaskController::VIEW],
        [TaskController::VIEW => AccessScope::Own],
    ));
    $viewerUser = $this->addMember($tenant, $viewerRole);
    $viewer = $this->membershipOf($tenant, $viewerUser);
    $mine = $this->inTenant($tenant, function () use ($owner, $viewer): Task {
        $project = app(ProjectManager::class)->create(['name' => 'Dependency scope'], $owner, $owner);
        $tasks = app(TaskManager::class);
        $hidden = $tasks->create($project, ['title' => 'Secret prerequisite'], $owner, $owner);
        $mine = $tasks->create($project, ['title' => 'Visible task'], $viewer, $owner);
        $tasks->addDependency($mine, $hidden, 'finish_to_start', $owner);

        return $mine;
    });

    $this->actingAsMember($tenant, $viewerUser)
        ->get($this->tenantUrl($tenant, "/projects/{$mine->project_id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tasks', 1)->where('tasks.0.title', 'Visible task')->where('tasks.0.dependencies', []));
});

it('adds an automation assignee without removing the existing ones unless asked to replace', function () {
    $tenant = $this->createTenant('projects-fix-assign');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $other = $this->membershipOf($tenant, $this->addMember($tenant));

    $this->inTenant($tenant, function () use ($owner, $other): void {
        $project = app(ProjectManager::class)->create(['name' => 'Assign'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'Shared task'], $owner, $owner);
        $action = app(AssignTaskAction::class);

        $action->execute(['task_id' => $task->id, 'membership_id' => $other->id], 'assign-1');

        expect(TaskAssignee::query()->where('task_id', $task->id)->pluck('membership_id')->sort()->values()->all())
            ->toBe(collect([$owner->id, $other->id])->sort()->values()->all());

        $action->execute(['task_id' => $task->id, 'membership_id' => $other->id, 'replace' => true], 'assign-2');

        expect(TaskAssignee::query()->where('task_id', $task->id)->pluck('membership_id')->all())->toBe([$other->id]);
    });
});
