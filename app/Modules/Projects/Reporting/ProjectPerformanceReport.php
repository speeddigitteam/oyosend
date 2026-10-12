<?php

declare(strict_types=1);

namespace App\Modules\Projects\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\Projects\Actions\PortfolioAnalytics;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\TaskController;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;

/**
 * Projects overlapping the period with progress, task counts and, for projects whose financials the
 * viewer may see, budget, spend and projected profit (the numbers of the Projects report).
 */
final class ProjectPerformanceReport implements ReportProvider
{
    public function __construct(
        private readonly PortfolioAnalytics $analytics,
        private readonly RecordAccess $records,
    ) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'projects.performance',
            module: 'projects',
            label: 'Project performance',
            description: 'Projects active in the period with progress and task counts. Budget and spend show only for projects whose financials you may see.',
            permission: ProjectController::VIEW,
            columns: [
                new ReportColumn('code', 'Code'),
                new ReportColumn('name', 'Project'),
                new ReportColumn('status', 'Status'),
                new ReportColumn('progress', 'Progress (%)', ColumnType::Integer),
                new ReportColumn('due_on', 'Due date', ColumnType::Date),
                new ReportColumn('tasks', 'Tasks', ColumnType::Integer),
                new ReportColumn('completed_tasks', 'Completed tasks', ColumnType::Integer),
                new ReportColumn('currency', 'Currency', default: false),
                new ReportColumn('budget_amount', 'Budget', ColumnType::Money, 'currency', default: false),
                new ReportColumn('spent_amount', 'Spent', ColumnType::Money, 'currency', default: false),
                new ReportColumn('billable_amount', 'Billable', ColumnType::Money, 'currency', default: false),
                new ReportColumn('projected_profit_amount', 'Projected profit', ColumnType::Money, 'currency', default: false),
            ],
            filters: [
                new ReportFilter('status', 'Status', FilterType::Select, [
                    'planned' => 'Planned', 'active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                ]),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $projects = $this->records->constrain(Project::query(), $context->user, ProjectController::VIEW)
            ->where(fn ($match) => $match->whereNull('due_on')->orWhere('due_on', '>=', $context->from))
            ->where(fn ($match) => $match->whereNull('starts_on')->orWhere('starts_on', '<=', $context->to))
            ->when($context->string('status'), fn ($query, string $status) => $query->where('status', $status));

        $visibleTasks = $this->records->constrain(Task::query(), $context->user, TaskController::VIEW)->select('project_tasks.id');
        $list = (clone $projects)
            ->withCount([
                'tasks' => fn ($query) => $query->whereIn('project_tasks.id', clone $visibleTasks),
                'tasks as completed_tasks_count' => fn ($query) => $query->whereIn('project_tasks.id', clone $visibleTasks)->where('status', 'completed'),
            ])
            ->orderBy('name')->orderBy('id')
            ->limit($context->rowLimit + 1)
            ->get();

        $financialProjects = $list->filter(fn (Project $project): bool => $this->records->allows($context->user, ProjectController::FINANCIALS, $project))->values();
        $financials = $this->analytics->financialsFor($financialProjects, $context->from, $context->to);

        return new ReportResult($list->map(static function (Project $project) use ($financials): array {
            $money = $financials[$project->id] ?? null;

            return [
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status->value,
                'progress' => $project->progress,
                'due_on' => $project->due_on?->toDateString(),
                'tasks' => (int) $project->getAttribute('tasks_count'),
                'completed_tasks' => (int) $project->getAttribute('completed_tasks_count'),
                'currency' => $project->currency,
                // Null = not reported: the viewer may not see this project's financials.
                'budget_amount' => $money === null ? null : (int) $money['budgetAmount'],
                'spent_amount' => $money === null ? null : (int) $money['spentAmount'],
                'billable_amount' => $money === null ? null : (int) $money['billableAmount'],
                'projected_profit_amount' => $money === null ? null : (int) $money['projectedProfitAmount'],
            ];
        })->all());
    }
}
