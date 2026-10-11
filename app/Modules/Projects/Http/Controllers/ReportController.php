<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Modules\Projects\Actions\PortfolioAnalytics;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController
{
    private const int PROJECTS_PER_PAGE = 50;

    public function __construct(private readonly PortfolioAnalytics $analytics, private readonly RecordAccess $records, private readonly ProjectControllerSupport $support) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $projectQuery = $this->records->constrain(Project::query(), $actor, ProjectController::VIEW)
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where(fn ($match) => $match->whereNull('due_on')->orWhere('due_on', '>=', $from)))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where(fn ($match) => $match->whereNull('starts_on')->orWhere('starts_on', '<=', $to)));
        $visibleTaskQuery = $this->analytics->constrainTasksToPeriod(
            $this->records->constrain(Task::query(), $actor, TaskController::VIEW),
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );
        $visibleTaskIds = (clone $visibleTaskQuery)->select('project_tasks.id');
        $taskQuery = (clone $visibleTaskQuery)->whereIn('project_id', (clone $projectQuery)->select('projects.id'));
        $summary = $this->analytics->portfolio(clone $projectQuery, clone $taskQuery);
        $paginator = (clone $projectQuery)
            ->withCount([
                'tasks' => fn ($query) => $query->whereIn('project_tasks.id', clone $visibleTaskIds),
                'tasks as completed_tasks_count' => fn ($query) => $query->whereIn('project_tasks.id', clone $visibleTaskIds)->where('status', 'completed'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->simplePaginate(self::PROJECTS_PER_PAGE)
            ->withQueryString();
        $projects = $paginator->getCollection();
        $financialProjects = $projects->filter(fn (Project $project): bool => $this->records->allows($actor, ProjectController::FINANCIALS, $project))->values();
        $financials = $this->analytics->financialsFor($financialProjects, $filters['from'] ?? null, $filters['to'] ?? null);
        $projectCount = (int) $summary['projectCount'];

        return Inertia::render('Modules/Projects/Pages/Reports/Index', [
            'summary' => $summary,
            'filters' => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null],
            'projects' => $projects->map(fn (Project $project): array => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'status' => $project->status->value, 'progress' => $project->progress, 'dueDate' => $project->due_on?->toDateString(), 'taskCount' => (int) $project->getAttribute('tasks_count'), 'completedTaskCount' => (int) $project->getAttribute('completed_tasks_count'), 'financials' => $financials[$project->id] ?? null])->all(),
            'projectsPagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => self::PROJECTS_PER_PAGE,
                'total' => $projectCount,
                'lastPage' => max(1, (int) ceil($projectCount / self::PROJECTS_PER_PAGE)),
                'hasMorePages' => $paginator->hasMorePages(),
                'previousPageUrl' => $paginator->previousPageUrl(),
                'nextPageUrl' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
