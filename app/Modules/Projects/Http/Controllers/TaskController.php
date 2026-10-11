<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TaskController
{
    public const string VIEW = 'projects.tasks.view';

    public const string EDIT = 'projects.tasks.edit';

    public const string ASSIGN = 'projects.tasks.assign';

    public const string COMMENT = 'projects.tasks.comment';

    public function __construct(private readonly RecordAccess $records, private readonly ProjectControllerSupport $support, private readonly TaskManager $tasks, private readonly TenantContext $context) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ProjectController::VIEW, $project);
        $input = $this->validated($request, $project);
        $actorMembership = $this->support->membership($actor);

        if (($input['assignee_id'] ?? null) !== null && $input['assignee_id'] !== $actorMembership->id) {
            abort_unless($actor->can(self::ASSIGN), 403);
        }

        $owner = $this->support->taskOwner($input['assignee_id'] ?? null, $actor);
        unset($input['assignee_id'], $input['status']);
        $task = $this->tasks->create($project, $input, $owner, $actorMembership);

        if (($request->input('status')) !== null) {
            $this->tasks->changeStatus($task, $this->normalizeStatus((string) $request->input('status')), $actorMembership);
        }

        Toast::success(__('Task created.'));

        return back();
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, self::EDIT, $task);
        $input = $this->validated($request, $project, $task);
        $status = $input['status'] ?? null;
        $normalizedStatus = $status === null ? null : $this->normalizeStatus((string) $status);
        $assigneeProvided = array_key_exists('assignee_id', $input);
        $assignee = $input['assignee_id'] ?? null;
        $assignments = null;

        $unchangedAssignee = $assignee !== null
            && $task->assignees()->where('membership_id', $assignee)->exists()
            && $task->assignees()->count() === 1;

        if ($assigneeProvided && ($assignee === null ? $task->assignees()->exists() : ! $unchangedAssignee)) {
            $this->records->authorize($actor, self::ASSIGN, $task);
            $assignments = $assignee === null ? [] : [$this->support->taskOwner($assignee, $actor)->id => 100];
        }

        unset($input['assignee_id'], $input['status']);
        $membership = $this->support->membership($actor);

        DB::transaction(function () use ($task, $input, $normalizedStatus, $assignments, $membership): void {
            $updated = $this->tasks->update($task, $input, $membership);

            if ($normalizedStatus !== null && $normalizedStatus !== $updated->status) {
                $updated = $this->tasks->changeStatus($updated, $normalizedStatus, $membership);
            }

            if ($assignments !== null) {
                $this->tasks->assign($updated, $assignments, $membership);
            }
        });

        Toast::success(__('Task saved.'));

        return back();
    }

    public function status(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, self::EDIT, $task);
        $input = $request->validate(['status' => ['required', 'string', Rule::in([...array_column(TaskStatus::cases(), 'value'), 'done'])]]);
        $this->tasks->changeStatus($task, $this->normalizeStatus($input['status']), $this->support->membership($actor));
        Toast::success(__('Task status changed.'));

        return back();
    }

    public function assign(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, self::ASSIGN, $task);
        $input = $request->validate(['assignees' => ['present', 'array', 'max:50'], 'assignees.*.membership_id' => ['required', 'string', 'size:26', 'distinct'], 'assignees.*.allocation_percent' => ['required', 'integer', 'between:0,100']]);
        $allocations = [];

        foreach ($input['assignees'] as $assignee) {
            $allocations[$assignee['membership_id']] = $assignee['allocation_percent'];
        }

        $this->support->guardTaskAssignments(array_keys($allocations), $actor);
        $this->tasks->assign($task, $allocations, $this->support->membership($actor));
        Toast::success(__('Task assignments saved.'));

        return back();
    }

    public function watch(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, self::VIEW, $task);
        $this->tasks->watch($task, $this->support->membership($actor));

        return back();
    }

    public function unwatch(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, self::VIEW, $task);
        $this->tasks->unwatch($task, $this->support->membership($actor));

        return back();
    }

    /** @return array<string, mixed> */
    public static function present(Task $task, User $actor, RecordAccess $records, string $timezone = 'UTC'): array
    {
        $tracked = array_key_exists('time_entries_sum_minutes', $task->getAttributes())
            ? (int) ($task->getAttribute('time_entries_sum_minutes') ?? 0)
            : null;

        return [
            'id' => $task->id, 'title' => $task->title, 'description' => $task->description, 'status' => $task->status->value, 'priority' => $task->priority->value,
            'startsAt' => $task->starts_at?->toIso8601String(), 'dueAt' => $task->due_at?->toIso8601String(), 'remindAt' => $task->remind_at?->toIso8601String(),
            'startsLocal' => $task->starts_at?->toImmutable()->setTimezone($timezone)->format('Y-m-d\\TH:i'), 'dueLocal' => $task->due_at?->toImmutable()->setTimezone($timezone)->format('Y-m-d\\TH:i'), 'remindLocal' => $task->remind_at?->toImmutable()->setTimezone($timezone)->format('Y-m-d\\TH:i'),
            'startDate' => $task->starts_at?->toImmutable()->setTimezone($timezone)->toDateString(), 'dueDate' => $task->due_at?->toImmutable()->setTimezone($timezone)->toDateString(),
            'estimatedMinutes' => $task->estimated_minutes, 'estimateMinutes' => $task->estimated_minutes, 'trackedMinutes' => $tracked,
            'progress' => $task->progress, 'position' => $task->position, 'parentId' => $task->parent_id, 'clientVisible' => $task->client_visible,
            'recurrenceRule' => $task->recurrence_rule,
            'sprint' => $task->sprint?->only(['id', 'name']), 'milestone' => $task->milestone?->only(['id', 'name']),
            'assignees' => $task->assignees->map(fn ($assignee): array => ['id' => $assignee->membership_id, 'name' => $assignee->membership->user->name, 'allocationPercent' => $assignee->allocation_percent])->all(),
            'checklist' => $task->checklistItems->map(fn ($item): array => ['id' => $item->id, 'label' => $item->title, 'completed' => $item->is_completed])->all(),
            'dependencies' => $task->relationLoaded('dependencies') ? $task->dependencies->map(fn ($dependency): array => ['id' => $dependency->depends_on_task_id, 'name' => $dependency->dependsOn->title])->all() : [],
            'comments' => $task->relationLoaded('comments') ? $task->comments->map(fn ($comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'internal' => $comment->is_internal,
                'client' => $comment->client_access_id !== null,
                'author' => $comment->author?->user->name ?? $comment->clientAccess?->contact->fullName() ?? __('Client'),
                'createdAt' => $comment->created_at->toIso8601String(),
            ])->all() : [],
            'watching' => $task->relationLoaded('watchers') && $task->watchers->isNotEmpty(),
            'editable' => $records->allows($actor, self::EDIT, $task),
            'assignable' => $records->allows($actor, self::ASSIGN, $task),
            'commentable' => $records->allows($actor, self::COMMENT, $task),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?Task $task = null): array
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:240'], 'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'string', Rule::in([...array_column(TaskStatus::cases(), 'value'), 'done'])],
            'priority' => ['required', 'string', Rule::in([...array_column(Priority::cases(), 'value'), 'medium'])],
            'parent_id' => ['nullable', 'string', 'size:26'], 'milestone_id' => ['nullable', 'string', 'size:26'], 'sprint_id' => ['nullable', 'string', 'size:26'],
            'starts_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'remind_at' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'estimated_minutes' => ['nullable', 'integer', 'between:0,10000000'], 'estimate_minutes' => ['nullable', 'integer', 'between:0,10000000'],
            'position' => ['nullable', 'integer', 'between:0,65535'], 'client_visible' => ['sometimes', 'boolean'], 'assignee_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'recurrence_rule' => ['nullable', 'array:frequency,interval,until'], 'recurrence_rule.frequency' => ['required_with:recurrence_rule', Rule::in(['daily', 'weekly', 'monthly'])], 'recurrence_rule.interval' => ['nullable', 'integer', 'between:1,365'], 'recurrence_rule.until' => ['nullable', 'date_format:Y-m-d'],
            'recurring' => ['sometimes', 'boolean'],
        ]);
        $timezone = $this->context->require()->timezone;
        $input['priority'] = ($input['priority'] ?? 'normal') === 'medium' ? Priority::Normal : Priority::from($input['priority'] ?? 'normal');
        $input['estimated_minutes'] = (int) ($input['estimated_minutes'] ?? $input['estimate_minutes'] ?? 0);
        unset($input['estimate_minutes']);

        foreach (['starts_at' => 'start_date', 'due_at' => 'due_date', 'remind_at' => null] as $field => $alias) {
            $provided = array_key_exists($field, $input) || ($alias !== null && array_key_exists($alias, $input));

            if ($provided) {
                $value = $input[$field] ?? ($alias === null ? null : ($input[$alias] ?? null));
                $input[$field] = $value === null ? null : CarbonImmutable::parse((string) $value, $timezone)->utc();
            }

            if ($alias !== null) {
                unset($input[$alias]);
            }
        }

        $recurringProvided = array_key_exists('recurring', $input);

        if (($input['recurring'] ?? false) === true && ($input['recurrence_rule'] ?? null) === null) {
            $input['recurrence_rule'] = ['frequency' => 'weekly', 'interval' => 1];
        } elseif ($recurringProvided && $input['recurring'] === false && ! array_key_exists('recurrence_rule', $input)) {
            $input['recurrence_rule'] = null;
        }

        unset($input['recurring']);

        if (array_key_exists('recurrence_rule', $input)) {
            $rule = $input['recurrence_rule'];

            if ($rule === null) {
                $input['next_recurrence_at'] = null;
            } else {
                $dueAt = $input['due_at'] ?? $task?->due_at?->toImmutable();
                $dueChanged = $task !== null
                    && array_key_exists('due_at', $input)
                    && ($dueAt instanceof CarbonImmutable ? $dueAt->getTimestamp() : null) !== $task->due_at?->getTimestamp();
                $shouldSchedule = $task === null || $task->recurrence_rule != $rule || $dueChanged;

                if ($shouldSchedule && (! $dueAt instanceof CarbonImmutable || ! $dueAt->isFuture())) {
                    throw ValidationException::withMessages(['due_at' => __('A recurring task needs a future due time.')]);
                }

                if ($shouldSchedule) {
                    $input['next_recurrence_at'] = $dueAt;
                }
            }
        }

        return $input;
    }

    private function guardTask(Project $project, Task $task): void
    {
        abort_unless($task->project_id === $project->id, 404);
    }

    private function normalizeStatus(string $status): TaskStatus
    {
        return $status === 'done' ? TaskStatus::Completed : TaskStatus::from($status);
    }
}
