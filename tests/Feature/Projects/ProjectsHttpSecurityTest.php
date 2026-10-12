<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RoleManager;
use App\Core\Contacts\ContactDirectory;
use App\Modules\Projects\Actions\ApprovalManager;
use App\Modules\Projects\Actions\CollaborationManager;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Actions\TimeExpenseManager;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Events\TaskReminderDue;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\TaskController;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

it('accepts workspace-local task reminders and emits each reminder once', function () {
    Carbon::setTestNow('2026-10-11 03:30:00 UTC');
    Event::fake([TaskReminderDue::class]);

    try {
        $tenant = $this->createTenant('projects-http-reminders', ['timezone' => 'Asia/Dhaka']);
        $this->entitle($tenant, 'projects');
        $owner = $this->ownerOf($tenant);
        $membership = $this->membershipOf($tenant, $owner);
        $project = $this->inTenant($tenant, fn (): Project => app(ProjectManager::class)->create(['name' => 'Reminder input'], $membership, $membership));

        $this->actingAsMember($tenant, $owner)
            ->post($this->tenantUrl($tenant, "/projects/{$project->id}/tasks"), [
                'title' => 'Send the launch reminder',
                'priority' => 'normal',
                'due_at' => '2026-10-12T10:00',
                'remind_at' => '2026-10-11T09:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $task = $this->inTenant($tenant, fn (): Task => Task::query()->where('project_id', $project->id)->sole());

        expect($task->remind_at?->toIso8601String())->toBe('2026-10-11T03:00:00+00:00');

        $this->actingAsMember($tenant, $owner)
            ->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tasks.0.remindAt', '2026-10-11T03:00:00+00:00')
                ->where('tasks.0.remindLocal', '2026-10-11T09:00'));

        $this->inTenant($tenant, function (): void {
            expect(app(TaskManager::class)->emitDueReminders())->toBe(1)
                ->and(app(TaskManager::class)->emitDueReminders())->toBe(0);
        });

        Event::assertDispatchedTimes(TaskReminderDue::class, 1);
    } finally {
        Carbon::setTestNow();
    }
});

it('projects the complete collaboration state into the project workspace', function () {
    $tenant = $this->createTenant('projects-http-collaboration');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $approverUser = $this->addMember($tenant, $this->createRole($tenant, 'Collaboration approvers', ['projects.approvals.decide']));
    $approver = $this->membershipOf($tenant, $approverUser);

    [$project, $task] = $this->inTenant($tenant, function () use ($ownerMembership, $approver): array {
        $project = app(ProjectManager::class)->create(['name' => 'Collaborative launch'], $ownerMembership, $ownerMembership);
        app(ProjectManager::class)->addMember($project, $approver, 'member', 75, $ownerMembership);
        $task = app(TaskManager::class)->create($project, [
            'title' => 'Prepare launch',
            'recurrence_rule' => ['frequency' => 'weekly', 'interval' => 2],
            'next_recurrence_at' => now()->addWeeks(2),
        ], $ownerMembership, $ownerMembership);
        app(TaskManager::class)->watch($task, $ownerMembership);
        app(CollaborationManager::class)->addChecklist($task, 'Confirm launch date', 0, $ownerMembership);
        app(CollaborationManager::class)->comment($task, 'Ready for review', $ownerMembership, [$approver->id], true);
        Attachment::query()->create(['entity_type' => 'task', 'entity_id' => $task->id, 'original_name' => 'brief.pdf', 'path' => 'test/brief.pdf', 'mime_type' => 'application/pdf', 'size' => 1024, 'uploaded_by' => $ownerMembership->id]);
        app(ApprovalManager::class)->request($project, ApprovalEntity::Task, $task->id, $ownerMembership, $approver, 'Please review');

        return [$project, $task];
    });

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Projects/Show')
            ->where('currentMembershipId', $ownerMembership->id)
            ->where('canRequestApprovals', true)
            ->has('members', 2)
            ->where('tasks.0.id', $task->id)
            ->where('tasks.0.watching', true)
            ->where('tasks.0.checklist.0.label', 'Confirm launch date')
            ->where('tasks.0.comments.0.body', 'Ready for review')
            ->where('tasks.0.comments.0.internal', true)
            ->where('tasks.0.attachments.0.name', 'brief.pdf')
            ->where('tasks.0.recurrenceRule.frequency', 'weekly')
            ->where('tasks.0.recurrenceRule.interval', 2)
            ->where('tasks.0.approvalPending', true)
            ->where('tasks.0.approvalRequestable', false));

    $scheduledAt = $task->refresh()->next_recurrence_at?->toIso8601String();

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}"), [
        'title' => 'Prepare launch safely',
        'priority' => 'normal',
        'recurrence_rule' => ['frequency' => 'weekly', 'interval' => 2],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($task->refresh()->recurrence_rule)->toEqual(['frequency' => 'weekly', 'interval' => 2])
        ->and($task->next_recurrence_at?->toIso8601String())->toBe($scheduledAt);

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}"), [
        'title' => 'Prepare launch safely',
        'priority' => 'normal',
        'recurrence_rule' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($task->refresh()->recurrence_rule)->toBeNull()
        ->and($task->next_recurrence_at)->toBeNull();

    $this->post($this->tenantUrl($tenant, "/projects/{$project->id}/tasks"), [
        'title' => 'Immediate duplicate',
        'priority' => 'normal',
        'recurring' => true,
    ])->assertSessionHasErrors('due_at');

    expect(Task::query()->where('title', 'Immediate duplicate')->exists())->toBeFalse();

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}/assignees"), [
        'assignees' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->inTenant($tenant, fn (): int => TaskAssignee::query()->where('task_id', $task->id)->count()))->toBe(0);

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}/assignees"), [
        'assignees' => [['membership_id' => $ownerMembership->id, 'allocation_percent' => 100]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}"), [
        'title' => $task->title,
        'priority' => 'normal',
        'assignee_id' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->inTenant($tenant, fn (): int => TaskAssignee::query()->where('task_id', $task->id)->count()))->toBe(0);
});

it('rolls back detail edits when project or task lifecycle validation fails', function () {
    $tenant = $this->createTenant('projects-http-atomic-updates');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);

    [$project, $task] = $this->inTenant($tenant, function () use ($ownerMembership): array {
        $projects = app(ProjectManager::class);
        $project = $projects->create(['name' => 'Atomic project'], $ownerMembership, $ownerMembership);
        $projects->changeStatus($project, ProjectStatus::Active, $ownerMembership);
        $task = app(TaskManager::class)->create($project, ['title' => 'Atomic task'], $ownerMembership, $ownerMembership);

        return [$project, $task];
    });

    $this->actingAsOwner($tenant)
        ->put($this->tenantUrl($tenant, "/projects/{$project->id}"), [
            'name' => 'Partially saved project',
            'priority' => 'normal',
            'status' => 'completed',
        ])->assertSessionHasErrors('status');

    expect($project->refresh()->name)->toBe('Atomic project');

    $this->inTenant($tenant, fn () => app(TaskManager::class)->changeStatus($task, TaskStatus::Completed, $ownerMembership));

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}/tasks/{$task->id}"), [
        'title' => 'Partially saved task',
        'priority' => 'normal',
        'status' => 'in_progress',
    ])->assertSessionHasErrors('status');

    expect($task->refresh()->title)->toBe('Atomic task')
        ->and($task->status)->toBe(TaskStatus::Completed);

    $this->inTenant($tenant, fn () => app(ProjectManager::class)->changeStatus($project, ProjectStatus::Completed, $ownerMembership));

    $this->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('project.taskCreatable', false));
});

it('projects approval request state into time and expense workspaces', function () {
    $tenant = $this->createTenant('projects-http-finance-approvals');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $approverUser = $this->addMember($tenant, $this->createRole($tenant, 'Finance request approvers', ['projects.approvals.decide', 'projects.time.approve']));
    $approver = $this->membershipOf($tenant, $approverUser);

    [$time, $expense] = $this->inTenant($tenant, function () use ($ownerMembership, $approver): array {
        $project = app(ProjectManager::class)->create(['name' => 'Approval projection'], $ownerMembership, $ownerMembership);
        $finance = app(TimeExpenseManager::class);
        $time = $finance->logTime($project, $ownerMembership, ['worked_on' => '2026-10-10', 'minutes' => 60, 'is_billable' => true], $ownerMembership);
        $finance->submitTime($time, $ownerMembership);
        $expense = $finance->createExpense($project, $ownerMembership, ['incurred_on' => '2026-10-10', 'description' => 'Taxi', 'amount' => 1250, 'is_billable' => false]);
        $finance->submitExpense($expense, $ownerMembership);
        app(ApprovalManager::class)->request($project, ApprovalEntity::TimeEntry, $time->id, $ownerMembership, $approver);

        return [$time, $expense];
    });

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, '/project-time'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Time/Index')
            ->where('currentMembershipId', $ownerMembership->id)
            ->has('approvalMembers', 2)
            ->where('entries.0.id', $time->id)
            ->where('entries.0.approvalPending', true)
            ->where('entries.0.approvable', false)
            ->where('entries.0.approvalRequestable', false));

    $this->get($this->tenantUrl($tenant, '/project-expenses'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Expenses/Index')
            ->where('currentMembershipId', $ownerMembership->id)
            ->has('approvalMembers', 2)
            ->where('expenses.0.id', $expense->id)
            ->where('expenses.0.approvalPending', false)
            ->where('expenses.0.approvalRequestable', true));
});

it('redacts and preserves project budgets without the financial permission', function () {
    $tenant = $this->createTenant('projects-http-budget');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $editor = $this->addMember($tenant, $this->createRole($tenant, 'Project editors', [ProjectController::VIEW, ProjectController::EDIT]));
    $project = $this->inTenant($tenant, fn (): Project => app(ProjectManager::class)->create(['name' => 'Private budget', 'budget_amount' => 50_000], $ownerMembership, $ownerMembership));

    $this->actingAsMember($tenant, $editor)
        ->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Projects/Show')
            ->where('project.budgetAmount', null)
            ->where('financials', null)
            ->where('canManageFinancials', false));

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}"), [
        'name' => 'Tampered budget',
        'priority' => 'normal',
        'budget_amount' => 1,
    ])->assertSessionHasErrors('budget_amount');

    expect($project->refresh()->name)->toBe('Private budget')
        ->and($project->budget_amount)->toBe(50_000);

    $this->put($this->tenantUrl($tenant, "/projects/{$project->id}"), [
        'name' => 'Safe edit',
        'priority' => 'high',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($project->refresh()->name)->toBe('Safe edit')
        ->and($project->budget_amount)->toBe(50_000);

    $this->post($this->tenantUrl($tenant, '/projects'), [
        'name' => 'No financial grant',
        'priority' => 'normal',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Project::query()->where('name', 'No financial grant')->sole()->budget_amount)->toBe(0);
});

it('applies financial record scope to the prospective project owner', function () {
    $tenant = $this->createTenant('projects-http-create-budget-scope');
    $this->entitle($tenant, 'projects');
    $ownerMembership = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $this->ownerOf($tenant),
        'Scoped project creators',
        null,
        [ProjectController::VIEW, ProjectController::EDIT, ProjectController::FINANCIALS],
        [ProjectController::EDIT => AccessScope::All, ProjectController::FINANCIALS => AccessScope::Own],
    ));
    $creator = $this->addMember($tenant, $role);
    $creatorMembership = $this->membershipOf($tenant, $creator);

    $this->actingAsMember($tenant, $creator)
        ->post($this->tenantUrl($tenant, '/projects'), [
            'name' => 'Someone else budget',
            'owner_id' => $ownerMembership->id,
            'priority' => 'normal',
            'budget_amount' => 25_000,
        ])->assertNotFound();

    expect(Project::query()->where('name', 'Someone else budget')->exists())->toBeFalse();

    $this->post($this->tenantUrl($tenant, '/projects'), [
        'name' => 'Own budget',
        'owner_id' => $creatorMembership->id,
        'priority' => 'normal',
        'budget_amount' => 25_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Project::query()->where('name', 'Own budget')->sole()->budget_amount)->toBe(25_000);
});

it('does not let member managers delegate financial visibility to a client', function () {
    $tenant = $this->createTenant('projects-http-client-finance');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $manager = $this->addMember($tenant, $this->createRole($tenant, 'Client access managers', [
        'core.contacts.view',
        ProjectController::VIEW,
        ProjectController::MEMBERS,
    ]));
    [$project, $contact] = $this->inTenant($tenant, function () use ($ownerMembership): array {
        $contact = app(ContactDirectory::class)->createContact(['first_name' => 'Client', 'email' => 'client@example.test'], $ownerMembership);
        $project = app(ProjectManager::class)->create(['name' => 'Controlled portal', 'contact_id' => $contact->id, 'budget_amount' => 75_000], $ownerMembership, $ownerMembership);

        return [$project, $contact];
    });

    $this->actingAsMember($tenant, $manager)
        ->post($this->tenantUrl($tenant, "/projects/{$project->id}/client-access"), [
            'contact_id' => $contact->id,
            'company_id' => null,
            'can_comment' => true,
            'can_view_financials' => true,
            'expires_at' => null,
        ])->assertNotFound();

    expect(ClientAccess::query()->count())->toBe(0);

    $this->post($this->tenantUrl($tenant, "/projects/{$project->id}/client-access"), [
        'contact_id' => $contact->id,
        'company_id' => null,
        'can_comment' => true,
        'can_view_financials' => false,
        'expires_at' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(ClientAccess::query()->sole()->can_view_financials)->toBeFalse();
});

it('protects time rates and out-of-scope task references', function () {
    $tenant = $this->createTenant('projects-http-time-scope');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $loggerRole = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $owner,
        'Scoped time loggers',
        null,
        [ProjectController::VIEW, TaskController::VIEW, 'projects.time.view', 'projects.time.log'],
        [TaskController::VIEW => AccessScope::Own],
    ));
    $logger = $this->addMember($tenant, $loggerRole);
    [$project, $hiddenTask] = $this->inTenant($tenant, function () use ($ownerMembership): array {
        $project = app(ProjectManager::class)->create(['name' => 'Scoped time'], $ownerMembership, $ownerMembership);
        $task = app(TaskManager::class)->create($project, ['title' => 'Owner-only task'], $ownerMembership, $ownerMembership);

        return [$project, $task];
    });

    $this->actingAsMember($tenant, $logger)
        ->post($this->tenantUrl($tenant, '/project-time'), [
            'project_id' => $project->id,
            'task_id' => $hiddenTask->id,
            'date' => '2026-10-10',
            'minutes' => 30,
        ])->assertNotFound();

    $this->post($this->tenantUrl($tenant, '/project-time'), [
        'project_id' => $project->id,
        'date' => '2026-10-10',
        'minutes' => 30,
        'billable_rate_amount' => 100_000,
        'cost_rate_amount' => 90_000,
    ])->assertNotFound();

    expect(TimeEntry::query()->count())->toBe(0);

    $this->post($this->tenantUrl($tenant, '/project-time'), [
        'project_id' => $project->id,
        'date' => '2026-10-10',
        'minutes' => 30,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(TimeEntry::query()->sole()->billable_rate_amount)->toBe(0)
        ->and(TimeEntry::query()->sole()->cost_rate_amount)->toBe(0);
});

it('scopes tracked task minutes to the viewer time permission', function () {
    $tenant = $this->createTenant('projects-http-tracked-time-scope');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    $ownTimeRole = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $ownerUser,
        'Own tracked time viewers',
        null,
        [ProjectController::VIEW, TaskController::VIEW, 'projects.time.view'],
        ['projects.time.view' => AccessScope::Own],
    ));
    $ownTimeUser = $this->addMember($tenant, $ownTimeRole);
    $ownTimeMember = $this->membershipOf($tenant, $ownTimeUser);
    $noTimeUser = $this->addMember($tenant, $this->createRole($tenant, 'Task-only viewers', [ProjectController::VIEW, TaskController::VIEW]));

    [$project, $task] = $this->inTenant($tenant, function () use ($owner, $ownTimeMember): array {
        $project = app(ProjectManager::class)->create(['name' => 'Private tracked time'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'Shared task'], $owner, $owner);
        app(TimeExpenseManager::class)->logTime($project, $owner, ['task_id' => $task->id, 'worked_on' => '2026-10-10', 'minutes' => 90], $owner);
        app(TimeExpenseManager::class)->logTime($project, $ownTimeMember, ['task_id' => $task->id, 'worked_on' => '2026-10-10', 'minutes' => 30], $ownTimeMember);

        return [$project, $task];
    });

    $this->actingAsMember($tenant, $ownTimeUser)
        ->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tasks.0.id', $task->id)->where('tasks.0.trackedMinutes', 30));

    $this->actingAsMember($tenant, $noTimeUser)
        ->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tasks.0.id', $task->id)->where('tasks.0.trackedMinutes', null));
});

it('uses tenant-local dates and returns a stable expense summary contract', function () {
    Carbon::setTestNow('2026-10-11 05:00:00 UTC');

    try {
        $tenant = $this->createTenant('projects-http-local-date', ['timezone' => 'Pacific/Honolulu', 'currency' => 'BDT']);
        $this->entitle($tenant, 'projects');
        $owner = $this->ownerOf($tenant);
        $ownerMembership = $this->membershipOf($tenant, $owner);
        $project = $this->inTenant($tenant, fn (): Project => app(ProjectManager::class)->create(['name' => 'Local dates'], $ownerMembership, $ownerMembership));

        $this->actingAsOwner($tenant)->post($this->tenantUrl($tenant, '/project-time'), [
            'project_id' => $project->id,
            'minutes' => 60,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post($this->tenantUrl($tenant, '/project-expenses'), [
            'project_id' => $project->id,
            'description' => 'Local taxi',
            'amount' => 1_250,
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect(TimeEntry::query()->sole()->worked_on->toDateString())->toBe('2026-10-10');

        $this->get($this->tenantUrl($tenant, '/project-expenses'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Projects/Pages/Expenses/Index')
                ->where('summary.currency', 'BDT')
                ->where('summary.totalAmount', '1250')
                ->where('summary.pendingAmount', '0')
                ->where('expenses.0.date', '2026-10-10')
                ->where('expenses.0.submittable', true));
    } finally {
        Carbon::setTestNow();
    }
});

it('applies report filters and redacts financial rows without financial access', function () {
    $tenant = $this->createTenant('projects-http-reports');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $reporter = $this->addMember($tenant, $this->createRole($tenant, 'Delivery reporters', [
        ProjectController::VIEW,
        'projects.reports.view',
    ]));

    $this->inTenant($tenant, function () use ($ownerMembership): void {
        $included = app(ProjectManager::class)->create(['name' => 'Included', 'starts_on' => '2026-10-01', 'due_on' => '2026-10-31', 'budget_amount' => 99_000], $ownerMembership, $ownerMembership);
        app(TaskManager::class)->create($included, ['title' => 'Hidden report task'], $ownerMembership, $ownerMembership);
        app(ProjectManager::class)->create(['name' => 'Excluded', 'starts_on' => '2026-12-01', 'due_on' => '2026-12-31', 'budget_amount' => 88_000], $ownerMembership, $ownerMembership);
    });

    $this->actingAsMember($tenant, $reporter)
        ->get($this->tenantUrl($tenant, '/project-reports?from=2026-10-01&to=2026-10-31'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Reports/Index')
            ->where('summary.projectCount', 1)
            ->where('summary.tasks', 0)
            ->has('summary.workload', 0)
            ->has('projects', 1)
            ->where('projects.0.name', 'Included')
            ->where('projects.0.taskCount', 0)
            ->where('projects.0.financials', null));
});

it('does not expose workspace member options to a project-only viewer', function () {
    $tenant = $this->createTenant('projects-http-member-options');
    $this->entitle($tenant, 'projects');
    $owner = $this->ownerOf($tenant);
    $ownerMembership = $this->membershipOf($tenant, $owner);
    $viewer = $this->addMember($tenant, $this->createRole($tenant, 'Project-only viewers', [ProjectController::VIEW]));
    $hidden = $this->addMember($tenant);
    $project = $this->inTenant($tenant, fn (): Project => app(ProjectManager::class)->create(['name' => 'Scoped directory'], $ownerMembership, $ownerMembership));

    $this->actingAsMember($tenant, $viewer)
        ->get($this->tenantUrl($tenant, '/projects'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Projects/Index')
            ->where('members', []));

    $this->get($this->tenantUrl($tenant, "/projects/{$project->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Projects/Show')
            ->where('memberOptions.0.id', $ownerMembership->id)
            ->missing('memberOptions.1')
            ->where('approvalMembers', [])
            ->where('taskForm.members', [])
            ->where('projectForm.owners', []));

    expect($hidden->name)->not->toBe($owner->name);
});
