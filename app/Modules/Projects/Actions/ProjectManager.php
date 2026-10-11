<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Events\ProjectCreated;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\ProjectTemplate;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskWatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProjectManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityRecorder $activity,
        private readonly TenantContext $context,
        private readonly TaskManager $tasks,
        private readonly UsageLimits $limits,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, TenantMembership $owner, ?TenantMembership $actor = null): Project
    {
        $attributes['name'] = $this->projectName($attributes['name'] ?? null);
        $this->guardMember($owner);

        if ($actor !== null) {
            $this->guardMember($actor);
        }

        $tenantId = $this->context->requireId();

        return DB::transaction(function () use ($attributes, $owner, $actor, $tenantId): Project {
            // Serialize the gauge check and insert so concurrent creates cannot both observe the same capacity.
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->limits->ensure('projects.active_projects', field: 'name');

            $template = $this->guardReferences($attributes);
            $attributes['currency'] = strtoupper((string) ($attributes['currency'] ?? $this->context->require()->currency));

            if ($attributes['currency'] !== $this->context->require()->currency) {
                throw ValidationException::withMessages(['currency' => __('Projects use the workspace currency.')]);
            }

            $attributes['budget_amount'] = ExactMoney::amount($attributes['budget_amount'] ?? 0, 'budget_amount');
            $attributes['status'] = ProjectStatus::Planned;
            $attributes['priority'] ??= Priority::Normal;
            $attributes['progress'] = 0;
            $attributes['completed_at'] = null;
            $project = new Project($attributes);

            if ($project->starts_on !== null && $project->due_on !== null && $project->due_on->isBefore($project->starts_on)) {
                throw ValidationException::withMessages(['due_on' => __('The due date must be on or after the start date.')]);
            }

            $project->id ??= (string) Str::ulid();
            $project->code = strtoupper(trim((string) ($attributes['code'] ?? ''))) ?: 'PRJ-'.strtoupper(substr($project->id, -8));
            $project->assignOwner($owner)->save();

            ProjectMember::query()->create([
                'project_id' => $project->id,
                'membership_id' => $owner->id,
                'role' => 'manager',
                'allocation_percent' => 100,
                'added_by' => $actor?->id,
            ]);

            $this->activity->record($project->id, 'projects.project.created', __('Project created.'), $actor);
            $this->audit->record('projects.project.created', $project, ['owner_id' => $owner->id, 'template_id' => $template?->id, 'budget_amount' => $project->budget_amount, 'currency' => $project->currency]);

            if ($template !== null) {
                $this->createTemplateTasks($project, $template, $owner, $actor);
            }

            ProjectCreated::dispatch($project->id, $project->name, $project->contact_id, $project->company_id, $project->owner_id);

            return $project->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Project $project, array $attributes, ?TenantMembership $actor = null): Project
    {
        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = $this->projectName($attributes['name']);
        }

        return DB::transaction(function () use ($project, $attributes, $actor): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            unset($attributes['status'], $attributes['completed_at'], $attributes['progress'], $attributes['currency'], $attributes['template_id']);
            $this->guardReferences($attributes);

            if (array_key_exists('budget_amount', $attributes)) {
                $attributes['budget_amount'] = ExactMoney::amount($attributes['budget_amount'], 'budget_amount');
            }

            $locked->fill($attributes);

            if ($locked->starts_on !== null && $locked->due_on !== null && $locked->due_on->isBefore($locked->starts_on)) {
                throw ValidationException::withMessages(['due_on' => __('The due date must be on or after the start date.')]);
            }

            if ($locked->isDirty()) {
                $changed = array_keys($locked->getDirty());
                $locked->save();
                $this->activity->record($locked->id, 'projects.project.updated', __('Project details updated.'), $actor, metadata: ['changed' => implode(',', $changed)]);
                $this->audit->record('projects.project.updated', $locked, ['changed' => $changed]);
            }

            return $locked;
        });
    }

    public function changeStatus(Project $project, ProjectStatus $status, ?TenantMembership $actor = null): Project
    {
        return DB::transaction(function () use ($project, $status, $actor): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            if (! $this->canTransition($locked->status, $status)) {
                throw ValidationException::withMessages(['status' => __('That project status transition is not allowed.')]);
            }

            if ($status === ProjectStatus::Completed && $locked->tasks()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['status' => __('Complete or cancel every open task before completing the project.')]);
            }

            $from = $locked->status;
            $locked->update([
                'status' => $status,
                'progress' => $status === ProjectStatus::Completed ? 100 : $locked->progress,
                'completed_at' => $status === ProjectStatus::Completed ? now() : null,
            ]);
            $this->activity->record($locked->id, 'projects.project.status_changed', __('Project status changed.'), $actor, metadata: ['from' => $from->value, 'to' => $status->value]);
            $this->audit->record('projects.project.status_changed', $locked, ['from' => $from->value, 'to' => $status->value]);

            return $locked;
        });
    }

    public function addMember(Project $project, TenantMembership $member, string $role, int $allocationPercent, ?TenantMembership $actor = null): ProjectMember
    {
        $this->guardMember($member);

        if ($actor !== null) {
            $this->guardMember($actor);
        }

        if ($allocationPercent < 0 || $allocationPercent > 100) {
            throw ValidationException::withMessages(['allocation_percent' => __('Allocation must be between 0 and 100 percent.')]);
        }

        return DB::transaction(function () use ($project, $member, $role, $allocationPercent, $actor): ProjectMember {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            /** @var ProjectMember $projectMember */
            $projectMember = ProjectMember::query()->updateOrCreate(
                ['project_id' => $project->id, 'membership_id' => $member->id],
                ['role' => $role, 'allocation_percent' => $allocationPercent, 'added_by' => $actor?->id],
            );
            $this->activity->record($project->id, 'projects.member.added', __('Project member added.'), $actor, metadata: ['membership_id' => $member->id, 'role' => $role]);
            $this->audit->record('projects.member.added', $project, ['membership_id' => $member->id, 'role' => $role]);

            return $projectMember;
        });
    }

    public function removeMember(Project $project, TenantMembership $member, ?TenantMembership $actor = null): void
    {
        $this->guardMember($member, active: false);

        if ($actor !== null) {
            $this->guardMember($actor);
        }

        DB::transaction(function () use ($project, $member, $actor): void {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($locked->owner_id === $member->id) {
                throw ValidationException::withMessages(['membership_id' => __('The project owner cannot be removed from the project.')]);
            }

            ProjectMember::query()->where('project_id', $project->id)->where('membership_id', $member->id)->delete();
            $taskIds = $project->tasks()->withTrashed()->pluck('id');
            TaskAssignee::query()->whereIn('task_id', $taskIds)->where('membership_id', $member->id)->delete();
            TaskWatcher::query()->whereIn('task_id', $taskIds)->where('membership_id', $member->id)->delete();
            $this->activity->record($project->id, 'projects.member.removed', __('Project member removed.'), $actor, metadata: ['membership_id' => $member->id]);
            $this->audit->record('projects.member.removed', $project, ['membership_id' => $member->id]);
        });
    }

    private function guardMember(TenantMembership $member, bool $active = true): void
    {
        if ($member->tenant_id !== $this->context->requireId() || ($active && $member->status !== MembershipStatus::Active)) {
            throw ValidationException::withMessages(['membership_id' => __('Choose an active workspace member.')]);
        }
    }

    private function projectName(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('The project name is required.')]);
        }

        return $name;
    }

    /** @param array<string, mixed> $attributes */
    private function guardReferences(array $attributes): ?ProjectTemplate
    {
        if (($attributes['contact_id'] ?? null) !== null) {
            Contact::query()->findOrFail($attributes['contact_id']);
        }

        if (($attributes['company_id'] ?? null) !== null) {
            Company::query()->findOrFail($attributes['company_id']);
        }

        if (($attributes['category_id'] ?? null) !== null) {
            ProjectCategory::query()->findOrFail($attributes['category_id']);
        }

        return ($attributes['template_id'] ?? null) === null ? null : ProjectTemplate::query()->where('is_active', true)->findOrFail($attributes['template_id']);
    }

    private function canTransition(ProjectStatus $from, ProjectStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            ProjectStatus::Planned => in_array($to, [ProjectStatus::Active, ProjectStatus::Cancelled], true),
            ProjectStatus::Active => in_array($to, [ProjectStatus::OnHold, ProjectStatus::Completed, ProjectStatus::Cancelled], true),
            ProjectStatus::OnHold => in_array($to, [ProjectStatus::Active, ProjectStatus::Cancelled], true),
            ProjectStatus::Completed, ProjectStatus::Cancelled => false,
        };
    }

    private function createTemplateTasks(Project $project, ProjectTemplate $template, TenantMembership $owner, ?TenantMembership $actor): void
    {
        foreach ($template->task_blueprints ?? [] as $position => $blueprint) {
            if (! is_string($blueprint['title'] ?? null) || trim($blueprint['title']) === '') {
                continue;
            }

            $this->tasks->create($project, [
                'title' => $blueprint['title'],
                'description' => is_string($blueprint['description'] ?? null) ? $blueprint['description'] : null,
                'priority' => Priority::tryFrom((string) ($blueprint['priority'] ?? 'normal')) ?? Priority::Normal,
                'estimated_minutes' => is_int($blueprint['estimated_minutes'] ?? null) ? $blueprint['estimated_minutes'] : 0,
                'position' => $position,
            ], $owner, $actor);
        }
    }
}
