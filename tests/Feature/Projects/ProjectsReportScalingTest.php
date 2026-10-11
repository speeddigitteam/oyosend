<?php

declare(strict_types=1);

use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('bounds report rows and batches financial aggregation across the page', function () {
    $tenant = $this->createTenant('projects-report-scaling');
    $this->entitle($tenant, 'projects');
    $ownerUser = $this->ownerOf($tenant);
    $owner = $this->membershipOf($tenant, $ownerUser);

    $this->inTenant($tenant, function () use ($owner): void {
        $now = now();
        $rows = [];

        for ($index = 1; $index <= 51; $index++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'code' => sprintf('PRJ-%03d', $index),
                'name' => sprintf('Project %02d', $index),
                'status' => ProjectStatus::Planned->value,
                'priority' => Priority::Normal->value,
                'currency' => 'BDT',
                'budget_amount' => 1_000,
                'progress' => 0,
                'owner_id' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Project::query()->insert($rows);

        $projectId = $rows[0]['id'];
        $timeRows = [];
        $expenseRows = [];

        for ($index = 0; $index < 501; $index++) {
            $timeRows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'project_id' => $projectId,
                'membership_id' => $owner->id,
                'worked_on' => '2026-10-10',
                'minutes' => 60,
                'is_billable' => true,
                'currency' => 'BDT',
                'billable_rate_amount' => 1,
                'cost_rate_amount' => 1,
                'status' => EntryStatus::Approved->value,
                'owner_id' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $expenseRows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'project_id' => $projectId,
                'description' => 'Expense '.$index,
                'incurred_on' => '2026-10-10',
                'currency' => 'BDT',
                'amount' => 1,
                'status' => EntryStatus::Approved->value,
                'submitted_by' => $owner->id,
                'owner_id' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TimeEntry::query()->insert($timeRows);
        Expense::query()->insert($expenseRows);
    });

    $timeEntryQueries = 0;
    $expenseQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$timeEntryQueries, &$expenseQueries): void {
        $sql = strtolower($query->sql);
        $timeEntryQueries += (int) str_contains($sql, 'project_time_entries');
        $expenseQueries += (int) str_contains($sql, 'project_expenses');
    });

    $this->actingAsMember($tenant, $ownerUser)
        ->get($this->tenantUrl($tenant, '/project-reports?from=2026-10-01&to=2026-10-31'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Reports/Index')
            ->where('summary.projectCount', 51)
            ->has('projects', 50)
            ->where('projects.0.name', 'Project 01')
            ->where('projects.0.financials.budgetAmount', '1000')
            ->where('projects.0.financials.spentAmount', '1002')
            ->where('projects.49.name', 'Project 50')
            ->where('projectsPagination.currentPage', 1)
            ->where('projectsPagination.perPage', 50)
            ->where('projectsPagination.total', 51)
            ->where('projectsPagination.lastPage', 2)
            ->where('projectsPagination.hasMorePages', true)
            ->where('projectsPagination.nextPageUrl', fn (?string $url): bool => $url !== null
                && str_contains($url, 'from=2026-10-01')
                && str_contains($url, 'to=2026-10-31')
                && str_contains($url, 'page=2')));

    expect($timeEntryQueries)->toBe(2)
        ->and($expenseQueries)->toBe(2);

    $this->get($this->tenantUrl($tenant, '/project-reports?from=2026-10-01&to=2026-10-31&page=2'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('projects', 1)
            ->where('projects.0.name', 'Project 51')
            ->where('projectsPagination.currentPage', 2)
            ->where('projectsPagination.hasMorePages', false)
            ->where('projectsPagination.previousPageUrl', fn (?string $url): bool => $url !== null
                && str_contains($url, 'from=2026-10-01')
                && str_contains($url, 'to=2026-10-31')
                && str_contains($url, 'page=1')));
});

it('uses tenant dates and applies the report period to tasks workload and financial facts', function () {
    Carbon::setTestNow('2026-10-11 01:00:00 UTC');

    try {
        $tenant = $this->createTenant('projects-report-period', ['timezone' => 'Pacific/Honolulu']);
        $this->entitle($tenant, 'projects');
        $ownerUser = $this->ownerOf($tenant);
        $owner = $this->membershipOf($tenant, $ownerUser);

        $this->inTenant($tenant, function () use ($owner): void {
            $projects = app(ProjectManager::class);
            $today = $projects->create(['name' => 'A Today', 'due_on' => '2026-10-10', 'budget_amount' => 10_000], $owner, $owner);
            $projects->create(['name' => 'B Yesterday', 'due_on' => '2026-10-09'], $owner, $owner);
            $projects->create(['name' => 'C Tomorrow', 'due_on' => '2026-10-11'], $owner, $owner);
            $tasks = app(TaskManager::class);

            $tasks->create($today, ['title' => 'Before', 'starts_at' => '2026-09-30 22:00:00 UTC', 'due_at' => '2026-09-30 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $inside = $tasks->create($today, ['title' => 'Inside', 'starts_at' => '2026-10-12 22:00:00 UTC', 'due_at' => '2026-10-12 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->create($today, ['title' => 'After', 'starts_at' => '2026-11-01 22:00:00 UTC', 'due_at' => '2026-11-01 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->create($today, ['title' => 'Overlapping', 'starts_at' => '2026-09-01 22:00:00 UTC', 'due_at' => '2026-10-01 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->create($today, ['title' => 'Start only before', 'starts_at' => '2026-09-30 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->create($today, ['title' => 'Start only inside', 'starts_at' => '2026-10-15 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->create($today, ['title' => 'Due only after', 'due_at' => '2026-11-01 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->create($today, ['title' => 'Due only inside', 'due_at' => '2026-10-16 22:00:00 UTC', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->create($today, ['title' => 'Undated', 'estimated_minutes' => 60], $owner, $owner);
            $tasks->changeStatus($inside, TaskStatus::Completed, $owner);

            foreach (['2026-09-30', '2026-10-12', '2026-11-01'] as $workedOn) {
                TimeEntry::query()->create([
                    'project_id' => $today->id,
                    'membership_id' => $owner->id,
                    'worked_on' => $workedOn,
                    'minutes' => 60,
                    'is_billable' => true,
                    'currency' => 'BDT',
                    'billable_rate_amount' => 6_000,
                    'cost_rate_amount' => 3_000,
                    'status' => EntryStatus::Approved,
                ]);
            }

            foreach (['2026-09-30' => 100, '2026-10-12' => 200, '2026-11-01' => 300] as $incurredOn => $amount) {
                Expense::query()->create([
                    'project_id' => $today->id,
                    'description' => 'Expense '.$incurredOn,
                    'incurred_on' => $incurredOn,
                    'currency' => 'BDT',
                    'amount' => $amount,
                    'status' => EntryStatus::Approved,
                    'submitted_by' => $owner->id,
                ]);
            }
        });

        $this->actingAsMember($tenant, $ownerUser)
            ->get($this->tenantUrl($tenant, '/project-reports?from=2026-10-01&to=2026-10-31'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.projectCount', 3)
                ->where('summary.overdueCount', 1)
                ->where('summary.tasks', 7)
                ->where('summary.completedTasks', 1)
                ->where('summary.workload.0.membershipId', $owner->id)
                ->where('summary.workload.0.allocatedMinutes', 360)
                ->where('summary.workload.0.taskCount', 6)
                ->where('projects.0.name', 'A Today')
                ->where('projects.0.taskCount', 7)
                ->where('projects.0.completedTaskCount', 1)
                ->where('projects.0.financials.budgetAmount', '10000')
                ->where('projects.0.financials.billableAmount', '6000')
                ->where('projects.0.financials.labourCostAmount', '3000')
                ->where('projects.0.financials.expenseAmount', '200')
                ->where('projects.0.financials.spentAmount', '3200')
                ->where('projects.0.financials.budgetRemainingAmount', '6800')
                ->where('projects.0.financials.projectedProfitAmount', '2800'));
    } finally {
        Carbon::setTestNow();
    }
});
