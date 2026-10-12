<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Enums\MilestoneStatus;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\SprintStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Events\TaskCompleted;
use App\Modules\Projects\Events\TaskCreated;
use App\Modules\Projects\Events\TaskReminderDue;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskDependency;
use App\Modules\Projects\Models\TaskWatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly ActivityRecorder $activity, private readonly TenantContext $context) {}

    /** @param array<string, mixed> $attributes */
    public function create(Project $project, array $attributes, ?TenantMembership $owner = null, ?TenantMembership $actor = null): Task
    {
        return DB::transaction(function () use ($project, $attributes, $owner, $actor): Task {
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedProject->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['project_id' => __('New tasks cannot be added to a completed or cancelled project.')]);
            }

            $this->guardTitle($attributes, required: true);

            if ($owner !== null) {
                $this->guardActiveMember($owner);
            }

            $this->guardReferences($lockedProject, $attributes);
            $attributes['project_id'] = $lockedProject->id;
            $attributes['status'] = TaskStatus::Backlog;
            $attributes['priority'] ??= Priority::Normal;
            $attributes['estimated_minutes'] ??= 0;
            $attributes['client_visible'] ??= false;
            $attributes['progress'] = 0;
            $attributes['completed_at'] = null;
            $attributes['reminder_sent_at'] = null;
            $task = new Task($attributes);

            if ($task->starts_at !== null && $task->due_at !== null && $task->due_at->isBefore($task->starts_at)) {
                throw ValidationException::withMessages(['due_at' => __('The due time must be on or after the start time.')]);
            }

            $task->assignOwner($owner ?? $actor)->save();

            if ($owner !== null) {
                $this->assign($task, [$owner->id => 100], $actor);
            }

            $this->activity->record($lockedProject->id, 'projects.task.created', __('Task created.'), $actor, $task->id);
            $this->audit->record('projects.task.created', $task, ['project_id' => $lockedProject->id]);
            TaskCreated::dispatch($task->id, $task->project_id, $task->title, $task->owner_id);
            $this->refreshProjectProgress($lockedProject);

            return $task;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Task $task, array $attributes, ?TenantMembership $actor = null): Task
    {
        return DB::transaction(function () use ($task, $attributes, $actor): Task {
            $project = Project::query()->whereKey($task->project_id)->lockForUpdate()->firstOrFail();
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            unset($attributes['project_id'], $attributes['status'], $attributes['completed_at'], $attributes['progress'], $attributes['automation_key']);
            $this->guardTitle($attributes);
            $this->guardReferences($project, $attributes, $locked);
            $locked->fill($attributes);

            if ($locked->isDirty('remind_at')) {
                $locked->reminder_sent_at = null;
            }

            if ($locked->starts_at !== null && $locked->due_at !== null && $locked->due_at->isBefore($locked->starts_at)) {
                throw ValidationException::withMessages(['due_at' => __('The due time must be on or after the start time.')]);
            }

            if ($locked->isDirty()) {
                $changed = array_keys($locked->getDirty());
                $locked->save();
                $this->activity->record($locked->project_id, 'projects.task.updated', __('Task details updated.'), $actor, $locked->id, ['changed' => implode(',', $changed)]);
                $this->audit->record('projects.task.updated', $locked, ['changed' => $changed]);
            }

            return $locked;
        });
    }

    public function changeStatus(Task $task, TaskStatus $status, ?TenantMembership $actor = null): Task
    {
        return DB::transaction(function () use ($task, $status, $actor): Task {
            $project = Project::query()->whereKey($task->project_id)->lockForUpdate()->firstOrFail();
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            if (in_array($locked->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => __('A completed or cancelled task cannot be reopened.')]);
            }

            if ($status === TaskStatus::Completed && TaskDependency::query()
                ->where('task_id', $locked->id)
                ->whereHas('dependsOn', fn ($query) => $query->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled]))
                ->exists()) {
                throw ValidationException::withMessages(['status' => __('Complete this task’s dependencies first.')]);
            }

            if (in_array($status, [TaskStatus::Completed, TaskStatus::Cancelled], true)
                && $locked->subtasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])->exists()) {
                throw ValidationException::withMessages(['status' => __('Complete or cancel every subtask first.')]);
            }

            $from = $locked->status;
            $locked->update(['status' => $status, 'progress' => $status === TaskStatus::Completed ? 100 : $locked->progress, 'completed_at' => $status === TaskStatus::Completed ? now() : null]);
            $this->activity->record($locked->project_id, 'projects.task.status_changed', __('Task status changed.'), $actor, $locked->id, ['from' => $from->value, 'to' => $status->value]);
            $this->audit->record('projects.task.status_changed', $locked, ['from' => $from->value, 'to' => $status->value]);

            if ($status === TaskStatus::Completed) {
                TaskCompleted::dispatch($locked->id, $locked->project_id, $locked->title);
            }

            $this->refreshProjectProgress($project);

            return $locked;
        });
    }

    /** @param array<string, int> $allocations membership id => percent */
    public function assign(Task $task, array $allocations, ?TenantMembership $actor = null, bool $replace = true): void
    {
        if ($actor !== null) {
            $this->guardActiveMember($actor);
        }

        if (count($allocations) !== count(array_unique(array_keys($allocations)))) {
            throw ValidationException::withMessages(['assignees' => __('Each assignee may appear only once.')]);
        }

        DB::transaction(function () use ($task, $allocations, $actor, $replace): void {
            Project::query()->whereKey($task->project_id)->lockForUpdate()->firstOrFail();
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $members = TenantMembership::query()->where('status', MembershipStatus::Active)->whereKey(array_keys($allocations))->get()->keyBy('id');

            if ($members->count() !== count($allocations)) {
                throw ValidationException::withMessages(['assignees' => __('One or more assignees are unavailable.')]);
            }

            if ($replace) {
                TaskAssignee::query()->where('task_id', $locked->id)->delete();
            } else {
                TaskAssignee::query()->where('task_id', $locked->id)->whereIn('membership_id', array_keys($allocations))->delete();
            }

            foreach ($allocations as $membershipId => $percent) {
                if ($percent < 0 || $percent > 100) {
                    throw ValidationException::withMessages(['assignees' => __('Allocation must be between 0 and 100 percent.')]);
                }

                ProjectMember::query()->firstOrCreate(
                    ['project_id' => $locked->project_id, 'membership_id' => $membershipId],
                    ['role' => 'member', 'allocation_percent' => $percent, 'added_by' => $actor?->id],
                );
                TaskAssignee::query()->create(['task_id' => $locked->id, 'membership_id' => $membershipId, 'allocation_percent' => $percent, 'assigned_at' => now()]);
            }

            $this->activity->record($locked->project_id, 'projects.task.assigned', __('Task assignments updated.'), $actor, $locked->id, ['assignee_count' => count($allocations)]);
            $this->audit->record('projects.task.assigned', $locked, ['membership_ids' => array_keys($allocations)]);
        });
    }

    public function watch(Task $task, TenantMembership $member): void
    {
        $this->guardActiveMember($member);
        TaskWatcher::query()->firstOrCreate(['task_id' => $task->id, 'membership_id' => $member->id], ['created_at' => now()]);
    }

    public function unwatch(Task $task, TenantMembership $member): void
    {
        TaskWatcher::query()->where('task_id', $task->id)->where('membership_id', $member->id)->delete();
    }

    public function addDependency(Task $task, Task $dependsOn, string $type = 'finish_to_start', ?TenantMembership $actor = null): TaskDependency
    {
        return DB::transaction(function () use ($task, $dependsOn, $type, $actor): TaskDependency {
            Project::query()->whereKey($task->project_id)->lockForUpdate()->firstOrFail();

            if ($task->project_id !== $dependsOn->project_id || $task->id === $dependsOn->id || $this->createsCycle($task, $dependsOn)) {
                throw ValidationException::withMessages(['depends_on_task_id' => __('A dependency must be another task in this project and cannot create a cycle.')]);
            }

            /** @var TaskDependency $dependency */
            $dependency = TaskDependency::query()->firstOrCreate(['task_id' => $task->id, 'depends_on_task_id' => $dependsOn->id], ['type' => $type]);
            $this->activity->record($task->project_id, 'projects.task.dependency_added', __('Task dependency added.'), $actor, $task->id, ['depends_on_task_id' => $dependsOn->id]);

            return $dependency;
        });
    }

    public function generateDueRecurrences(int $limit = 100): int
    {
        $created = 0;
        $due = Task::query()->whereNotNull('recurrence_rule')->whereNotNull('next_recurrence_at')->where('next_recurrence_at', '<=', now())->orderBy('next_recurrence_at')->limit($limit)->get();

        foreach ($due as $source) {
            try {
                $this->generateRecurrenceFor($source, $created);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $created;
    }

    private function generateRecurrenceFor(Task $source, int &$created): void
    {
        DB::transaction(function () use ($source, &$created): void {
            $project = Project::query()->whereKey($source->project_id)->lockForUpdate()->firstOrFail();
            /** @var Task|null $locked */
            $locked = Task::query()->whereKey($source->id)->lockForUpdate()->first();

            if ($locked === null || $locked->recurrence_rule === null || $locked->next_recurrence_at === null || $locked->next_recurrence_at->isFuture()) {
                return;
            }

            if (in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true)) {
                $locked->update(['next_recurrence_at' => null]);

                return;
            }

            $locked->loadMissing('owner');
            $runAt = $locked->next_recurrence_at->toImmutable();
            $nextRunAt = $this->advanceOccurrence($runAt, $locked->recurrence_rule);

            if ($nextRunAt === null || ! $this->occurrenceIsWithinRule($nextRunAt, $locked->recurrence_rule)) {
                $locked->update(['next_recurrence_at' => null]);

                return;
            }

            $owner = $locked->owner?->status === MembershipStatus::Active ? $locked->owner : null;
            $key = 'recurrence:'.$locked->id.':'.$runAt->format('YmdHis');
            $task = $this->create($project, [
                'title' => $locked->title,
                'description' => $locked->description,
                'priority' => $locked->priority,
                'estimated_minutes' => $locked->estimated_minutes,
                'starts_at' => $this->shiftDate($locked->starts_at?->toImmutable(), $runAt, $locked->recurrence_rule),
                'due_at' => $this->shiftDate($locked->due_at?->toImmutable(), $runAt, $locked->recurrence_rule),
                'remind_at' => $this->shiftDate($locked->remind_at?->toImmutable(), $runAt, $locked->recurrence_rule),
                'recurrence_rule' => $locked->recurrence_rule,
                'next_recurrence_at' => $nextRunAt,
                'automation_key' => $key,
            ], $owner);
            $locked->update(['next_recurrence_at' => null]);
            $created++;
            $this->audit->record('projects.task.recurred', $task, ['source_task_id' => $locked->id]);
        });
    }

    public function emitDueReminders(int $limit = 250): int
    {
        $count = 0;

        Task::query()->whereNull('reminder_sent_at')->whereNotNull('remind_at')->where('remind_at', '<=', now())->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])
            ->whereHas('project', fn ($query) => $query->whereNotIn('status', [ProjectStatus::Completed, ProjectStatus::Cancelled]))
            ->orderBy('remind_at')->orderBy('id')->limit($limit)->get()
            ->each(function (Task $task) use (&$count): void {
                DB::transaction(function () use ($task, &$count): void {
                    $project = Project::query()->whereKey($task->project_id)->lockForUpdate()->first();

                    if ($project === null || in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true)) {
                        return;
                    }

                    $updated = Task::query()->whereKey($task->id)
                        ->whereNull('reminder_sent_at')
                        ->whereNotNull('remind_at')
                        ->where('remind_at', '<=', now())
                        ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])
                        ->update(['reminder_sent_at' => now()]);

                    if ($updated === 1) {
                        $task->refresh();
                        $this->activity->record($task->project_id, 'projects.task.reminder_due', __('Task reminder is due.'), taskId: $task->id);
                        TaskReminderDue::dispatch($task->id, $task->project_id, $task->title, $task->owner_id);
                        $count++;
                    }
                });
            });

        return $count;
    }

    private function refreshProjectProgress(Project $project): void
    {
        $total = $project->tasks()->whereNull('parent_id')->where('status', '!=', TaskStatus::Cancelled)->count();
        $complete = $project->tasks()->whereNull('parent_id')->where('status', TaskStatus::Completed)->count();
        $project->update(['progress' => $total === 0 ? 0 : intdiv($complete * 100, $total)]);
    }

    /** @param array<string, mixed> $attributes */
    private function guardTitle(array $attributes, bool $required = false): void
    {
        if (($required && ! array_key_exists('title', $attributes))
            || (array_key_exists('title', $attributes) && (! is_string($attributes['title']) || trim($attributes['title']) === ''))) {
            throw ValidationException::withMessages(['title' => __('The task title is required.')]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function guardReferences(Project $project, array $attributes, ?Task $task = null): void
    {
        foreach (['parent_id' => Task::class, 'milestone_id' => Milestone::class, 'sprint_id' => Sprint::class] as $field => $model) {
            if (($attributes[$field] ?? null) === null) {
                continue;
            }

            $record = $model::query()->findOrFail($attributes[$field]);
            $changingReference = $task === null || $task->getAttribute($field) !== $record->id;

            if ($record->project_id !== $project->id || ($record instanceof Task && $task !== null && $record->id === $task->id)) {
                throw ValidationException::withMessages([$field => __('The selected record does not belong to this project.')]);
            }

            if ($changingReference && (($record instanceof Milestone && $record->status !== MilestoneStatus::Open)
                || ($record instanceof Sprint && in_array($record->status, [SprintStatus::Completed, SprintStatus::Cancelled], true))
                || ($record instanceof Task && in_array($record->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)))) {
                throw ValidationException::withMessages([$field => __('The selected planning container is already closed.')]);
            }

            if ($field === 'parent_id' && $record instanceof Task && $task !== null && $this->isDescendant($record, $task)) {
                throw ValidationException::withMessages([$field => __('A subtask hierarchy cannot contain a cycle.')]);
            }
        }
    }

    private function guardActiveMember(TenantMembership $member): void
    {
        if ($member->tenant_id !== $this->context->requireId() || $member->status !== MembershipStatus::Active) {
            throw ValidationException::withMessages(['membership_id' => __('Choose an active workspace member.')]);
        }
    }

    private function createsCycle(Task $task, Task $dependsOn): bool
    {
        $pending = [$dependsOn->id];
        $visited = [];

        while ($pending !== []) {
            $current = array_pop($pending);

            if ($current === $task->id) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            array_push($pending, ...TaskDependency::query()->where('task_id', $current)->pluck('depends_on_task_id')->all());
        }

        return false;
    }

    private function isDescendant(Task $candidateParent, Task $task): bool
    {
        $current = $candidateParent;

        while ($current->parent_id !== null) {
            if ($current->parent_id === $task->id) {
                return true;
            }

            $current = Task::query()->findOrFail($current->parent_id);
        }

        return false;
    }

    /** @param array<string, mixed>|null $rule */
    private function occurrenceIsWithinRule(CarbonImmutable $occurrence, ?array $rule): bool
    {
        $until = is_string($rule['until'] ?? null)
            ? CarbonImmutable::parse($rule['until'], $this->context->require()->timezone)->endOfDay()->utc()
            : null;

        return $until === null || $occurrence->lessThanOrEqualTo($until);
    }

    /** @param array<string, mixed>|null $rule */
    private function shiftDate(?CarbonImmutable $date, CarbonImmutable $runAt, ?array $rule): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }

        $next = $this->advanceOccurrence($date, $rule);

        return $next ?? $runAt;
    }

    /** @param array<string, mixed>|null $rule */
    private function advanceOccurrence(CarbonImmutable $from, ?array $rule): ?CarbonImmutable
    {
        $frequency = is_string($rule['frequency'] ?? null) ? $rule['frequency'] : null;
        $interval = is_int($rule['interval'] ?? null) ? max(1, min(365, $rule['interval'])) : 1;
        $local = $from->setTimezone($this->context->require()->timezone);
        $next = match ($frequency) {
            'daily' => $local->addDays($interval),
            'weekly' => $local->addWeeks($interval),
            'monthly' => $local->addMonthsNoOverflow($interval),
            default => null,
        };

        return $next?->utc();
    }
}
