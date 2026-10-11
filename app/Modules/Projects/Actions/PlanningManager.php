<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Projects\Enums\MilestoneStatus;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\SprintStatus;
use App\Modules\Projects\Events\MilestoneCompleted;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlanningManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly ActivityRecorder $activity) {}

    /** @param array<string, mixed> $attributes */
    public function createMilestone(Project $project, array $attributes, ?TenantMembership $actor = null): Milestone
    {
        return DB::transaction(function () use ($project, $attributes, $actor): Milestone {
            $lockedProject = $this->lockOpenProject($project);

            /** @var Milestone $milestone */
            $milestone = Milestone::query()->create([...$attributes, 'project_id' => $lockedProject->id, 'status' => MilestoneStatus::Open, 'completed_at' => null]);
            $this->activity->record($lockedProject->id, 'projects.milestone.created', __('Milestone created.'), $actor, metadata: ['milestone_id' => $milestone->id]);
            $this->audit->record('projects.milestone.created', $milestone, ['project_id' => $lockedProject->id]);

            return $milestone;
        });
    }

    public function changeMilestoneStatus(Milestone $milestone, MilestoneStatus $status, ?TenantMembership $actor = null): Milestone
    {
        return DB::transaction(function () use ($milestone, $status, $actor): Milestone {
            Project::query()->whereKey($milestone->project_id)->lockForUpdate()->firstOrFail();
            $locked = Milestone::query()->whereKey($milestone->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            if ($locked->status !== MilestoneStatus::Open) {
                throw ValidationException::withMessages(['status' => __('A completed or cancelled milestone cannot be reopened.')]);
            }

            if ($status === MilestoneStatus::Completed && $locked->tasks()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['status' => __('Complete or cancel every milestone task first.')]);
            }

            $locked->update(['status' => $status, 'completed_at' => $status === MilestoneStatus::Completed ? now() : null]);
            $this->activity->record($locked->project_id, 'projects.milestone.status_changed', __('Milestone status changed.'), $actor, metadata: ['milestone_id' => $locked->id, 'status' => $status->value]);
            $this->audit->record('projects.milestone.status_changed', $locked, ['status' => $status->value]);

            if ($status === MilestoneStatus::Completed) {
                MilestoneCompleted::dispatch($locked->id, $locked->project_id, $locked->name);
            }

            return $locked;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createSprint(Project $project, array $attributes, ?TenantMembership $actor = null): Sprint
    {
        if ((string) $attributes['ends_on'] < (string) $attributes['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => __('The sprint end date must be on or after its start date.')]);
        }

        return DB::transaction(function () use ($project, $attributes, $actor): Sprint {
            $lockedProject = $this->lockOpenProject($project);

            /** @var Sprint $sprint */
            $sprint = Sprint::query()->create([...$attributes, 'project_id' => $lockedProject->id, 'status' => SprintStatus::Planned, 'completed_at' => null]);
            $this->activity->record($lockedProject->id, 'projects.sprint.created', __('Sprint created.'), $actor, metadata: ['sprint_id' => $sprint->id]);
            $this->audit->record('projects.sprint.created', $sprint, ['project_id' => $lockedProject->id]);

            return $sprint;
        });
    }

    public function changeSprintStatus(Sprint $sprint, SprintStatus $status, ?TenantMembership $actor = null): Sprint
    {
        return DB::transaction(function () use ($sprint, $status, $actor): Sprint {
            Project::query()->whereKey($sprint->project_id)->lockForUpdate()->firstOrFail();
            $locked = Sprint::query()->whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            $allowed = match ($locked->status) {
                SprintStatus::Planned => [SprintStatus::Active, SprintStatus::Cancelled],
                SprintStatus::Active => [SprintStatus::Completed, SprintStatus::Cancelled],
                SprintStatus::Completed, SprintStatus::Cancelled => [],
            };

            if ($locked->status !== $status && ! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => __('That sprint status transition is not allowed.')]);
            }

            $locked->update(['status' => $status, 'completed_at' => $status === SprintStatus::Completed ? now() : null]);
            $this->activity->record($locked->project_id, 'projects.sprint.status_changed', __('Sprint status changed.'), $actor, metadata: ['sprint_id' => $locked->id, 'status' => $status->value]);
            $this->audit->record('projects.sprint.status_changed', $locked, ['status' => $status->value]);

            return $locked;
        });
    }

    private function lockOpenProject(Project $project): Project
    {
        $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

        if (in_array($locked->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['project_id' => __('Planning items cannot be added to a completed or cancelled project.')]);
        }

        return $locked;
    }
}
