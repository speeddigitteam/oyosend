<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PortfolioAnalytics
{
    private const int FINANCIAL_CHUNK_SIZE = 500;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{
     *     currency: string,
     *     budgetAmount: numeric-string,
     *     billableAmount: numeric-string,
     *     labourCostAmount: numeric-string,
     *     expenseAmount: numeric-string,
     *     spentAmount: numeric-string,
     *     budgetRemainingAmount: numeric-string,
     *     projectedProfitAmount: numeric-string
     * }
     */
    public function financials(Project $project): array
    {
        return $this->financialsFor(collect([$project]))[$project->id];
    }

    /**
     * Compute any number of project totals with one time-entry query and one
     * expense query per chunk. Keyset chunks keep aggregation memory-bounded
     * while exact per-entry rounding and integer-string arithmetic stay in PHP.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, array{
     *     currency: string,
     *     budgetAmount: numeric-string,
     *     billableAmount: numeric-string,
     *     labourCostAmount: numeric-string,
     *     expenseAmount: numeric-string,
     *     spentAmount: numeric-string,
     *     budgetRemainingAmount: numeric-string,
     *     projectedProfitAmount: numeric-string
     * }>
     */
    public function financialsFor(Collection $projects, ?string $from = null, ?string $to = null): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $currencies = [];
        $billable = [];
        $labour = [];
        $expenses = [];

        foreach ($projects as $project) {
            $currencies[$project->id] = $project->currency;
            $billable[$project->id] = '0';
            $labour[$project->id] = '0';
            $expenses[$project->id] = '0';
        }

        $projectIds = array_keys($currencies);

        $entryQuery = TimeEntry::query()->whereIn('project_id', $projectIds)->where('status', EntryStatus::Approved)
            ->when($from, fn (Builder $query, string $date) => $query->where('worked_on', '>=', $date))
            ->when($to, fn (Builder $query, string $date) => $query->where('worked_on', '<=', $date))
            ->select(['id', 'project_id', 'minutes', 'is_billable', 'currency', 'billable_rate_amount', 'cost_rate_amount']);

        foreach ($entryQuery->lazyById(self::FINANCIAL_CHUNK_SIZE) as $entry) {
            if (($currencies[$entry->project_id] ?? null) !== $entry->currency) {
                continue;
            }

            if ($entry->is_billable) {
                $billable[$entry->project_id] = ExactMoney::sumString([
                    $billable[$entry->project_id],
                    ExactMoney::hourly($entry->minutes, $entry->billable_rate_amount),
                ]);
            }

            $labour[$entry->project_id] = ExactMoney::sumString([
                $labour[$entry->project_id],
                ExactMoney::hourly($entry->minutes, $entry->cost_rate_amount),
            ]);
        }

        $expenseQuery = Expense::query()->whereIn('project_id', $projectIds)->where('status', EntryStatus::Approved)
            ->when($from, fn (Builder $query, string $date) => $query->where('incurred_on', '>=', $date))
            ->when($to, fn (Builder $query, string $date) => $query->where('incurred_on', '<=', $date))
            ->select(['id', 'project_id', 'currency', 'amount']);

        foreach ($expenseQuery->lazyById(self::FINANCIAL_CHUNK_SIZE) as $expense) {
            if (($currencies[$expense->project_id] ?? null) !== $expense->currency) {
                continue;
            }

            $expenses[$expense->project_id] = ExactMoney::sumString([$expenses[$expense->project_id], $expense->amount]);
        }

        $financials = [];

        foreach ($projects as $project) {
            $spent = ExactMoney::sumString([$labour[$project->id], $expenses[$project->id]]);

            $financials[$project->id] = [
                'currency' => $project->currency,
                'budgetAmount' => (string) $project->budget_amount,
                'billableAmount' => $billable[$project->id],
                'labourCostAmount' => $labour[$project->id],
                'expenseAmount' => $expenses[$project->id],
                'spentAmount' => $spent,
                'budgetRemainingAmount' => ExactMoney::sumString([$project->budget_amount, '-'.$spent]),
                'projectedProfitAmount' => ExactMoney::sumString([$billable[$project->id], '-'.$spent]),
            ];
        }

        return $financials;
    }

    /**
     * @param  Builder<Task>|null  $taskQuery
     * @return list<array<string, int|string>>
     */
    public function workload(?Project $project = null, ?Builder $taskQuery = null): array
    {
        $taskQuery ??= Task::query();
        $taskQuery->whereNotIn('status', ['completed', 'cancelled']);

        if ($project !== null) {
            $taskQuery->where('project_id', $project->id);
        }

        $assignments = TaskAssignee::query()
            ->join('project_tasks', 'project_tasks.id', '=', 'project_task_assignees.task_id')
            ->whereIn('project_task_assignees.task_id', (clone $taskQuery)->select('project_tasks.id'))
            ->groupBy('project_task_assignees.membership_id')
            ->orderBy('project_task_assignees.membership_id')
            ->select('project_task_assignees.membership_id')
            ->selectRaw('SUM(FLOOR(project_tasks.estimated_minutes * project_task_assignees.allocation_percent / 100)) as allocated_minutes')
            ->selectRaw('COUNT(*) as task_count')
            ->get();
        $members = TenantMembership::query()->whereKey($assignments->pluck('membership_id'))->with('user:id,name')->get()->keyBy('id');
        $workload = [];

        foreach ($assignments as $assignment) {
            $member = $members->get($assignment->membership_id);

            if ($member === null) {
                continue;
            }

            $workload[] = [
                'membershipId' => $assignment->membership_id,
                'name' => $member->user->name,
                'allocatedMinutes' => (int) $assignment->getAttribute('allocated_minutes'),
                'taskCount' => (int) $assignment->getAttribute('task_count'),
            ];
        }

        return $workload;
    }

    /**
     * Treat missing endpoints as open-ended and completely undated tasks as
     * belonging to every report window.
     *
     * @param  Builder<Task>  $taskQuery
     * @return Builder<Task>
     */
    public function constrainTasksToPeriod(Builder $taskQuery, ?string $from, ?string $to): Builder
    {
        $timezone = $this->context->require()->timezone;

        if ($from !== null) {
            $fromAt = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
            $taskQuery->where(function (Builder $overlap) use ($fromAt): void {
                $overlap->whereNull('due_at')->orWhere('due_at', '>=', $fromAt);
            });
        }

        if ($to !== null) {
            $toAt = CarbonImmutable::parse($to, $timezone)->endOfDay()->utc();
            $taskQuery->where(function (Builder $overlap) use ($toAt): void {
                $overlap->whereNull('starts_at')->orWhere('starts_at', '<=', $toAt);
            });
        }

        return $taskQuery;
    }

    /**
     * @param  Builder<Project>|null  $projectQuery
     * @param  Builder<Task>|null  $taskQuery
     * @return array<string, mixed>
     */
    public function portfolio(?Builder $projectQuery = null, ?Builder $taskQuery = null): array
    {
        $projectQuery ??= Project::query();
        $taskQuery ??= Task::query();
        $today = CarbonImmutable::now($this->context->require()->timezone)->toDateString();

        return [
            'projectCount' => (clone $projectQuery)->count(),
            'activeCount' => (clone $projectQuery)->whereIn('status', ['planned', 'active', 'on_hold'])->count(),
            'overdueCount' => (clone $projectQuery)->whereNotNull('due_on')->where('due_on', '<', $today)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'tasks' => (clone $taskQuery)->count(),
            'completedTasks' => (clone $taskQuery)->where('status', 'completed')->count(),
            'workload' => $this->workload(taskQuery: clone $taskQuery),
        ];
    }
}
