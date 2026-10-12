<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RoleManager;
use App\Modules\Projects\Actions\PortfolioAnalytics;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Actions\TimeExpenseManager;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Events\TimeEntryApproved;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\TaskController;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('validates time references and moves a time entry through one approval decision', function () {
    Event::fake([TimeEntryApproved::class]);
    $tenant = $this->createTenant('projects-time');
    $this->entitle($tenant, 'projects');
    $approver = $this->membershipOf($tenant, $this->addMember($tenant, $this->createRole($tenant, 'Time approvers', ['projects.time.approve', 'projects.expenses.approve'])));
    $foreignTenant = $this->createTenant('projects-time-foreign');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $foreign = $this->membershipOf($foreignTenant, $this->ownerOf($foreignTenant));

    $this->inTenant($tenant, function () use ($owner, $foreign, $approver): void {
        $projectManager = app(ProjectManager::class);
        $project = $projectManager->create(['name' => 'Time project'], $owner, $owner);
        $otherProject = $projectManager->create(['name' => 'Other project'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'Timed task'], $owner, $owner);
        $otherTask = app(TaskManager::class)->create($otherProject, ['title' => 'Other task'], $owner, $owner);
        $manager = app(TimeExpenseManager::class);

        expect(fn () => $manager->logTime($project, $foreign, ['worked_on' => '2026-10-10', 'minutes' => 60]))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->logTime($project, $owner, ['worked_on' => '2026-10-10', 'minutes' => 0]))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->logTime($project, $owner, ['task_id' => $otherTask->id, 'worked_on' => '2026-10-10', 'minutes' => 60]))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->logTime($project, $owner, [
                'worked_on' => '2026-10-10',
                'started_at' => '2026-10-10 11:00:00',
                'ended_at' => '2026-10-10 10:00:00',
                'minutes' => 60,
            ]))
            ->toThrow(ValidationException::class);

        $entry = $manager->logTime($project, $owner, [
            'task_id' => $task->id,
            'worked_on' => '2026-10-10',
            'minutes' => 90,
            'is_billable' => true,
            'billable_rate_amount' => '10001',
            'cost_rate_amount' => '6001',
            'status' => EntryStatus::Approved,
            'approved_by' => $owner->id,
            'approved_at' => now(),
        ], $owner);

        expect($entry->status)->toBe(EntryStatus::Draft)
            ->and($entry->currency)->toBe('BDT')
            ->and($entry->owner_id)->toBe($owner->id)
            ->and($entry->approved_by)->toBeNull()
            ->and($entry->approved_at)->toBeNull();

        $manager->submitTime($entry, $owner);
        $manager->decideTime($entry, true, $approver);

        expect(fn () => $manager->decideTime($entry, false, $approver))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->submitTime($entry, $owner))
            ->toThrow(ValidationException::class);

        expect($entry->refresh()->status)->toBe(EntryStatus::Approved)
            ->and($entry->approved_by)->toBe($approver->id)
            ->and($entry->approved_at)->not->toBeNull();
    });

    Event::assertDispatchedTimes(TimeEntryApproved::class, 1);
});

it('keeps expense approval immutable and computes exact project financials', function () {
    $tenant = $this->createTenant('projects-financials');
    $this->entitle($tenant, 'projects');
    $approver = $this->membershipOf($tenant, $this->addMember($tenant, $this->createRole($tenant, 'Finance approvers', ['projects.time.approve', 'projects.expenses.approve'])));
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner, $approver): void {
        $project = app(ProjectManager::class)->create([
            'name' => 'Financial project',
            'currency' => 'bdt',
            'budget_amount' => 50_000,
        ], $owner, $owner);
        $manager = app(TimeExpenseManager::class);
        $entry = $manager->logTime($project, $owner, [
            'worked_on' => '2026-10-10',
            'minutes' => 90,
            'is_billable' => true,
            'billable_rate_amount' => 10_001,
            'cost_rate_amount' => 6_001,
        ], $owner);
        $manager->submitTime($entry, $owner);
        $manager->decideTime($entry, true, $approver);

        $expense = $manager->createExpense($project, $owner, [
            'description' => 'Hosting',
            'incurred_on' => '2026-10-10',
            'amount' => '1234',
            'is_billable' => true,
            'status' => EntryStatus::Approved,
            'approved_by' => $owner->id,
            'approved_at' => now(),
        ]);
        expect($expense->status)->toBe(EntryStatus::Draft)
            ->and($expense->approved_by)->toBeNull()
            ->and($expense->approved_at)->toBeNull();
        $submittedExpense = $manager->submitExpense($expense, $owner);
        $manager->decideExpense($submittedExpense, true, $approver);

        // Drafts and other currencies never enter approved project totals.
        TimeEntry::query()->create([
            'project_id' => $project->id,
            'membership_id' => $owner->id,
            'worked_on' => '2026-10-10',
            'minutes' => 600,
            'currency' => 'BDT',
            'billable_rate_amount' => 1_000_000,
            'cost_rate_amount' => 1_000_000,
            'status' => EntryStatus::Draft,
        ]);
        Expense::query()->create([
            'project_id' => $project->id,
            'description' => 'Foreign currency',
            'incurred_on' => '2026-10-10',
            'currency' => 'USD',
            'amount' => 999_999,
            'status' => EntryStatus::Approved,
            'submitted_by' => $owner->id,
        ]);

        expect($project->currency)->toBe('BDT')
            ->and($expense->refresh()->status)->toBe(EntryStatus::Approved)
            ->and($expense->currency)->toBe('BDT')
            ->and(fn () => $manager->decideExpense($submittedExpense, false, $approver))->toThrow(ValidationException::class)
            ->and(app(PortfolioAnalytics::class)->financials($project))->toBe([
                'currency' => 'BDT',
                'budgetAmount' => '50000',
                'billableAmount' => '15002',
                'labourCostAmount' => '9002',
                'expenseAmount' => '1234',
                'spentAmount' => '10236',
                'budgetRemainingAmount' => '39764',
                'projectedProfitAmount' => '4766',
            ]);
    });
});

it('paginates every finance row while computing summaries across the full scope', function () {
    $tenant = $this->createTenant('projects-finance-summary-cap');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);

    $this->inTenant($tenant, function () use ($owner): void {
        $project = app(ProjectManager::class)->create(['name' => 'Large finance project'], $owner, $owner);
        $now = now();
        $timeRows = [];
        $expenseRows = [];

        for ($index = 0; $index < 501; $index++) {
            $timeRows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'project_id' => $project->id,
                'membership_id' => $owner->id,
                'worked_on' => '2026-10-10',
                'minutes' => 1,
                'is_billable' => true,
                'currency' => 'BDT',
                'billable_rate_amount' => 0,
                'cost_rate_amount' => 0,
                'status' => EntryStatus::Submitted->value,
                'owner_id' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $expenseRows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'project_id' => $project->id,
                'description' => 'Expense '.$index,
                'incurred_on' => '2026-10-10',
                'currency' => 'BDT',
                'amount' => 2,
                'is_billable' => true,
                'status' => EntryStatus::Submitted->value,
                'submitted_by' => $owner->id,
                'owner_id' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TimeEntry::query()->insert($timeRows);
        Expense::query()->insert($expenseRows);
    });

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, '/project-time'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 50)
            ->where('summary.totalMinutes', 501)
            ->where('summary.billableMinutes', 501)
            ->where('summary.pendingMinutes', 501)
            ->where('entriesPagination.currentPage', 1)
            ->where('entriesPagination.perPage', 50)
            ->where('entriesPagination.total', 501)
            ->where('entriesPagination.lastPage', 11)
            ->where('entriesPagination.hasMorePages', true));

    $this->get($this->tenantUrl($tenant, '/project-time?page=11'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('summary.totalMinutes', 501)
            ->where('entriesPagination.currentPage', 11)
            ->where('entriesPagination.hasMorePages', false)
            ->where('entriesPagination.nextPageUrl', null));

    $this->get($this->tenantUrl($tenant, '/project-expenses'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('expenses', 50)
            ->where('summary.totalAmount', '1002')
            ->where('summary.pendingAmount', '1002')
            ->where('expensesPagination.currentPage', 1)
            ->where('expensesPagination.perPage', 50)
            ->where('expensesPagination.total', 501)
            ->where('expensesPagination.lastPage', 11)
            ->where('expensesPagination.hasMorePages', true));

    $this->get($this->tenantUrl($tenant, '/project-expenses?page=11'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('expenses', 1)
            ->where('summary.totalAmount', '1002')
            ->where('expensesPagination.currentPage', 11)
            ->where('expensesPagination.hasMorePages', false)
            ->where('expensesPagination.nextPageUrl', null));
});

it('paginates and searches finance task options without bypassing record scope', function () {
    $tenant = $this->createTenant('projects-finance-task-options');
    $foreignTenant = $this->createTenant('projects-finance-task-options-foreign');
    $this->entitle($tenant, 'projects');
    $this->entitle($foreignTenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    $foreignOwner = $this->membershipOf($foreignTenant, $this->ownerOf($foreignTenant));
    $loggerRole = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
        $ownerUser,
        'Scoped finance task selectors',
        null,
        [ProjectController::VIEW, TaskController::VIEW, 'projects.time.log'],
        [TaskController::VIEW => AccessScope::Own],
    ));
    $loggerUser = $this->addMember($tenant, $loggerRole);
    $logger = $this->membershipOf($tenant, $loggerUser);
    $viewerUser = $this->addMember($tenant, $this->createRole($tenant, 'Non-finance task viewers', [ProjectController::VIEW, TaskController::VIEW]));
    [$project, $visibleTask] = $this->inTenant($tenant, function () use ($owner, $logger): array {
        $project = app(ProjectManager::class)->create(['name' => 'Task option project'], $owner, $owner);
        app(TaskManager::class)->create($project, ['title' => 'Hidden owner task'], $owner, $owner);
        $visibleTask = app(TaskManager::class)->create($project, ['title' => 'Visible logger task'], $logger, $owner);

        return [$project, $visibleTask];
    });
    $foreignProject = $this->inTenant($foreignTenant, fn () => app(ProjectManager::class)->create(['name' => 'Foreign options'], $foreignOwner, $foreignOwner));

    $this->actingAsMember($tenant, $loggerUser)
        ->getJson($this->tenantUrl($tenant, "/project-task-options?project_id={$project->id}"))
        ->assertOk()
        ->assertJsonCount(1, 'tasks')
        ->assertJsonPath('tasks.0.id', $visibleTask->id)
        ->assertJsonPath('pagination.currentPage', 1)
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('filters.projectId', $project->id);

    $this->getJson($this->tenantUrl($tenant, "/project-task-options?project_id={$foreignProject->id}"))
        ->assertNotFound();

    $this->actingAsMember($tenant, $viewerUser)
        ->getJson($this->tenantUrl($tenant, "/project-task-options?project_id={$project->id}"))
        ->assertForbidden();
});

it('returns stable searchable pages from the finance task-options endpoint', function () {
    $tenant = $this->createTenant('projects-finance-task-option-pages');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);
    $project = $this->inTenant($tenant, function () use ($owner) {
        $project = app(ProjectManager::class)->create(['name' => 'Many task options'], $owner, $owner);
        $now = now();
        $rows = [];

        for ($index = 1; $index <= 55; $index++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'project_id' => $project->id,
                'title' => sprintf('Task %03d', $index),
                'status' => 'backlog',
                'priority' => 'normal',
                'owner_id' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rows[] = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $owner->tenant_id,
            'project_id' => $project->id,
            'title' => 'Unique search needle',
            'status' => 'backlog',
            'priority' => 'normal',
            'owner_id' => $owner->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        Task::query()->insert($rows);

        return $project;
    });

    $this->actingAsMember($tenant, $ownerUser)
        ->getJson($this->tenantUrl($tenant, "/project-task-options?project_id={$project->id}"))
        ->assertOk()
        ->assertJsonCount(50, 'tasks')
        ->assertJsonPath('tasks.0.name', 'Task 001')
        ->assertJsonPath('pagination.perPage', 50)
        ->assertJsonPath('pagination.total', 56)
        ->assertJsonPath('pagination.lastPage', 2)
        ->assertJsonPath('pagination.hasMorePages', true)
        ->assertJsonPath('pagination.nextPageUrl', fn (?string $url): bool => $url !== null
            && str_contains($url, 'project_id='.$project->id)
            && str_contains($url, 'page=2'));

    $this->getJson($this->tenantUrl($tenant, "/project-task-options?project_id={$project->id}&page=2"))
        ->assertOk()
        ->assertJsonCount(6, 'tasks')
        ->assertJsonPath('tasks.5.name', 'Unique search needle')
        ->assertJsonPath('pagination.currentPage', 2)
        ->assertJsonPath('pagination.hasMorePages', false);

    $this->getJson($this->tenantUrl($tenant, "/project-task-options?project_id={$project->id}&search=needle"))
        ->assertOk()
        ->assertJsonCount(1, 'tasks')
        ->assertJsonPath('tasks.0.name', 'Unique search needle')
        ->assertJsonPath('filters.search', 'needle');
});
