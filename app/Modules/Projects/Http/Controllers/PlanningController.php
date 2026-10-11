<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Modules\Projects\Actions\PlanningManager;
use App\Modules\Projects\Enums\MilestoneStatus;
use App\Modules\Projects\Enums\SprintStatus;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlanningController
{
    public function __construct(private readonly RecordAccess $records, private readonly ProjectControllerSupport $support, private readonly PlanningManager $planning) {}

    public function storeMilestone(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ProjectController::EDIT, $project);
        $input = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:10000'], 'due_on' => ['required', 'date_format:Y-m-d']]);
        $this->planning->createMilestone($project, $input, $this->support->membership($actor));
        Toast::success(__('Milestone created.'));

        return back();
    }

    public function milestoneStatus(Request $request, Project $project, Milestone $milestone): RedirectResponse
    {
        $actor = $this->support->actor($request);
        abort_unless($milestone->project_id === $project->id, 404);
        $this->records->authorize($actor, ProjectController::EDIT, $project);
        $input = $request->validate(['status' => ['required', Rule::enum(MilestoneStatus::class)]]);
        $this->planning->changeMilestoneStatus($milestone, MilestoneStatus::from($input['status']), $this->support->membership($actor));
        Toast::success(__('Milestone status changed.'));

        return back();
    }

    public function storeSprint(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ProjectController::EDIT, $project);
        $input = $request->validate(['name' => ['required', 'string', 'max:160'], 'goal' => ['nullable', 'string', 'max:10000'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']]);
        $this->planning->createSprint($project, $input, $this->support->membership($actor));
        Toast::success(__('Sprint created.'));

        return back();
    }

    public function sprintStatus(Request $request, Project $project, Sprint $sprint): RedirectResponse
    {
        $actor = $this->support->actor($request);
        abort_unless($sprint->project_id === $project->id, 404);
        $this->records->authorize($actor, ProjectController::EDIT, $project);
        $input = $request->validate(['status' => ['required', Rule::enum(SprintStatus::class)]]);
        $this->planning->changeSprintStatus($sprint, SprintStatus::from($input['status']), $this->support->membership($actor));
        Toast::success(__('Sprint status changed.'));

        return back();
    }
}
