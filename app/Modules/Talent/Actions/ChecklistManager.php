<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Enums\AssigneeRole;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Enums\ChecklistStatus;
use App\Modules\Talent\Enums\TaskStatus;
use App\Modules\Talent\Events\ChecklistCompleted;
use App\Modules\Talent\Events\ChecklistStarted;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\ChecklistTemplate;
use App\Modules\Talent\Models\TemplateTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Onboarding and offboarding: templates, and checklists started from them. Starting from an event
 * is idempotent through the checklist's unique `source_key`, so a redelivered or duplicated event
 * creates nothing twice.
 */
final class ChecklistManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TalentSettings $settings,
        private readonly EmployeeLookup $employees,
    ) {}

    /**
     * @param  array{name: string, kind: string, is_default?: bool, is_active?: bool, tasks?: list<array<string, mixed>>}  $attributes
     */
    public function saveTemplate(?ChecklistTemplate $template, array $attributes): ChecklistTemplate
    {
        $tasks = $attributes['tasks'] ?? [];

        if (count($tasks) > 60) {
            throw ValidationException::withMessages(['tasks' => __('A template can have at most 60 tasks.')]);
        }

        foreach ($tasks as $index => $task) {
            $role = AssigneeRole::from((string) ($task['assignee_role'] ?? 'hr'));

            if ($role === AssigneeRole::Person) {
                $this->employees->require((string) ($task['assignee_employee_id'] ?? ''), "tasks.{$index}.assignee_employee_id");
            }
        }

        return DB::transaction(function () use ($template, $attributes, $tasks): ChecklistTemplate {
            $kind = ChecklistKind::from($attributes['kind']);
            $isDefault = (bool) ($attributes['is_default'] ?? false);
            $isActive = (bool) ($attributes['is_active'] ?? true);

            if ($isDefault && ! $isActive) {
                throw ValidationException::withMessages(['is_active' => __('The default template must be active.')]);
            }

            $template ??= new ChecklistTemplate;

            if ($template->exists && $template->kind !== $kind) {
                throw ValidationException::withMessages(['kind' => __('The kind of a template cannot change.')]);
            }

            $template->fill(['name' => $attributes['name'], 'kind' => $kind, 'is_default' => $isDefault, 'is_active' => $isActive]);

            try {
                $template->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('A template with that name already exists.')]);
            }

            if ($isDefault) {
                ChecklistTemplate::query()->where('kind', $kind)->whereKeyNot($template->id)->update(['is_default' => false]);
            }

            if (array_key_exists('tasks', $attributes)) {
                TemplateTask::query()->where('template_id', $template->id)->delete();

                foreach ($tasks as $position => $task) {
                    $role = AssigneeRole::from((string) ($task['assignee_role'] ?? 'hr'));
                    TemplateTask::query()->create([
                        'template_id' => $template->id,
                        'title' => (string) $task['title'],
                        'description' => $task['description'] ?? null,
                        'position' => ($position + 1) * 10,
                        'assignee_role' => $role,
                        'assignee_employee_id' => $role === AssigneeRole::Person ? (string) $task['assignee_employee_id'] : null,
                        'due_offset_days' => (int) ($task['due_offset_days'] ?? 0),
                    ]);
                }
            }

            return $template;
        });
    }

    /** The active default template of a kind, or null (then nothing starts automatically). */
    public function defaultTemplate(ChecklistKind $kind): ?ChecklistTemplate
    {
        return ChecklistTemplate::query()->where('kind', $kind)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('created_at')->first();
    }

    /**
     * Start a checklist for an employee. Returns the existing one when `$sourceKey` was used before,
     * and null when no template applies.
     */
    public function start(
        EmployeeSnapshot $employee,
        ChecklistKind $kind,
        ?ChecklistTemplate $template,
        string $sourceKey,
        ?string $anchorDate = null,
        ?TenantMembership $actor = null,
    ): ?Checklist {
        $existing = Checklist::query()->where('source_key', $sourceKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $template ??= $this->defaultTemplate($kind);
        $template?->loadMissing('tasks');

        if ($template === null || ! $template->is_active || $template->kind !== $kind) {
            return null;
        }

        $anchor = CarbonImmutable::parse($anchorDate ?? match ($kind) {
            ChecklistKind::Onboarding => $employee->joinedOn,
            ChecklistKind::Offboarding => $employee->lastWorkingOn ?? $employee->terminatedOn ?? $this->settings->today()->toDateString(),
        }, 'UTC');

        try {
            return DB::transaction(function () use ($employee, $kind, $template, $sourceKey, $anchor, $actor): Checklist {
                $checklist = new Checklist([
                    'employee_id' => $employee->id,
                    'kind' => $kind,
                    'template_id' => $template->id,
                    'name' => $template->name,
                    'anchor_date' => $anchor->toDateString(),
                    'status' => ChecklistStatus::Open,
                    'source_key' => $sourceKey,
                    'created_by' => $actor?->id,
                ]);
                $checklist->placeForEmployee($employee)->save();

                $count = 0;

                foreach ($template->tasks as $task) {
                    ChecklistTask::query()->create([
                        'checklist_id' => $checklist->id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'position' => $task->position,
                        'assignee_role' => $task->assignee_role,
                        'assignee_employee_id' => $this->resolveAssignee($task, $employee),
                        'due_on' => $anchor->addDays($task->due_offset_days)->toDateString(),
                        'status' => TaskStatus::Pending,
                    ]);
                    $count++;
                }

                $this->audit->record('talent.checklist.started', $checklist, ['kind' => $kind->value, 'tasks' => $count], $actor?->user);
                ChecklistStarted::dispatch([
                    'checklist_id' => $checklist->id, 'employee_id' => $employee->id, 'membership_id' => $employee->membershipId,
                    'kind' => $kind->value, 'task_count' => $count,
                ]);

                if ($count === 0) {
                    $this->complete($checklist);
                }

                return $checklist;
            });
        } catch (UniqueConstraintViolationException) {
            return Checklist::query()->where('source_key', $sourceKey)->first();
        }
    }

    /** @param array{title: string, description?: string|null, assignee_role?: string, assignee_employee_id?: string|null, due_on?: string|null} $attributes */
    public function addTask(Checklist $checklist, array $attributes): ChecklistTask
    {
        return DB::transaction(function () use ($checklist, $attributes): ChecklistTask {
            /** @var Checklist $locked */
            $locked = Checklist::query()->whereKey($checklist->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ChecklistStatus::Cancelled) {
                throw ValidationException::withMessages(['title' => __('This checklist was cancelled.')]);
            }

            $role = AssigneeRole::from($attributes['assignee_role'] ?? 'hr');
            $employee = $this->employees->require($locked->employee_id, 'employee_id', allowTerminated: true);
            $assignee = match ($role) {
                AssigneeRole::Employee => $employee->id,
                AssigneeRole::Manager => $employee->managerId,
                AssigneeRole::Hr => null,
                AssigneeRole::Person => $this->employees->require((string) ($attributes['assignee_employee_id'] ?? ''), 'assignee_employee_id')->id,
            };

            $task = ChecklistTask::query()->create([
                'checklist_id' => $locked->id,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'position' => ((int) ChecklistTask::query()->where('checklist_id', $locked->id)->max('position')) + 10,
                'assignee_role' => $role,
                'assignee_employee_id' => $assignee,
                'due_on' => $attributes['due_on'] ?? null,
                'status' => TaskStatus::Pending,
            ]);

            if ($locked->status === ChecklistStatus::Completed) {
                $locked->status = ChecklistStatus::Open;
                $locked->completed_at = null;
                $locked->save();
            }

            return $task;
        });
    }

    public function completeTask(ChecklistTask $task, ?TenantMembership $actor, ?string $note = null): ChecklistTask
    {
        return $this->setTaskStatus($task, TaskStatus::Done, $actor, $note);
    }

    public function skipTask(ChecklistTask $task, ?TenantMembership $actor, ?string $note = null): ChecklistTask
    {
        return $this->setTaskStatus($task, TaskStatus::Skipped, $actor, $note);
    }

    public function reopenTask(ChecklistTask $task, ?TenantMembership $actor): ChecklistTask
    {
        return $this->setTaskStatus($task, TaskStatus::Pending, $actor, null);
    }

    public function cancel(Checklist $checklist, ?TenantMembership $actor): Checklist
    {
        return DB::transaction(function () use ($checklist, $actor): Checklist {
            /** @var Checklist $locked */
            $locked = Checklist::query()->whereKey($checklist->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ChecklistStatus::Open) {
                throw ValidationException::withMessages(['status' => __('Only an open checklist can be cancelled.')]);
            }

            $locked->status = ChecklistStatus::Cancelled;
            $locked->save();
            $this->audit->record('talent.checklist.cancelled', $locked, [], $actor?->user);

            return $locked;
        });
    }

    private function setTaskStatus(ChecklistTask $task, TaskStatus $to, ?TenantMembership $actor, ?string $note): ChecklistTask
    {
        return DB::transaction(function () use ($task, $to, $actor, $note): ChecklistTask {
            /** @var ChecklistTask $locked */
            $locked = ChecklistTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            /** @var Checklist $checklist */
            $checklist = Checklist::query()->whereKey($locked->checklist_id)->lockForUpdate()->firstOrFail();

            if ($checklist->status === ChecklistStatus::Cancelled) {
                throw ValidationException::withMessages(['status' => __('This checklist was cancelled.')]);
            }

            if ($locked->status === $to) {
                return $locked;
            }

            $locked->status = $to;
            $locked->completed_at = $to === TaskStatus::Pending ? null : now();
            $locked->completed_by = $to === TaskStatus::Pending ? null : $actor?->id;
            $locked->note = $note ?? $locked->note;
            $locked->save();

            $pending = ChecklistTask::query()->where('checklist_id', $checklist->id)->where('status', TaskStatus::Pending)->exists();

            if (! $pending && $checklist->status === ChecklistStatus::Open) {
                $this->complete($checklist);
            } elseif ($pending && $checklist->status === ChecklistStatus::Completed) {
                $checklist->status = ChecklistStatus::Open;
                $checklist->completed_at = null;
                $checklist->save();
            }

            return $locked;
        });
    }

    private function complete(Checklist $checklist): void
    {
        $checklist->status = ChecklistStatus::Completed;
        $checklist->completed_at = now();
        $checklist->save();
        $this->audit->record('talent.checklist.completed', $checklist, ['kind' => $checklist->kind->value]);

        $employee = $this->employees->find($checklist->employee_id);
        ChecklistCompleted::dispatch([
            'checklist_id' => $checklist->id, 'employee_id' => $checklist->employee_id,
            'membership_id' => $employee->membershipId ?? $checklist->owner_id, 'kind' => $checklist->kind->value,
        ]);
    }

    private function resolveAssignee(TemplateTask $task, EmployeeSnapshot $employee): ?string
    {
        return match ($task->assignee_role) {
            AssigneeRole::Employee => $employee->id,
            AssigneeRole::Manager => $employee->managerId,
            AssigneeRole::Person => $task->assignee_employee_id,
            AssigneeRole::Hr => null,
        };
    }
}
