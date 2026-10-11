<?php

declare(strict_types=1);

namespace App\Modules\Projects\Widgets;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\TaskController;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;

final class ProjectsOverviewWidget
{
    public function __construct(private readonly RecordAccess $records) {}

    /** @return array<string, int> */
    public function __invoke(User $user): array
    {
        $activeProjects = $this->records->constrain(Project::query(), $user, ProjectController::VIEW)
            ->whereIn('status', [ProjectStatus::Planned, ProjectStatus::Active, ProjectStatus::OnHold])->count();
        $tasks = $user->can(TaskController::VIEW)
            ? $this->records->constrain(Task::query(), $user, TaskController::VIEW)
            : Task::query()->whereRaw('1 = 0');

        return [
            'activeProjects' => $activeProjects,
            'openTasks' => (clone $tasks)->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])->count(),
            'overdueTasks' => (clone $tasks)->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])->where('due_at', '<', now())->count(),
            'dueSoonTasks' => (clone $tasks)->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])->whereBetween('due_at', [now(), now()->addDays(7)])->count(),
        ];
    }
}
