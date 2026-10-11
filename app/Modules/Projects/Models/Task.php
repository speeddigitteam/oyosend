<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string|null $parent_id
 * @property string|null $milestone_id
 * @property string|null $sprint_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property Priority $priority
 * @property Carbon|null $starts_at
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property int $estimated_minutes
 * @property int $progress
 * @property int $position
 * @property bool $client_visible
 * @property array<string, mixed>|null $recurrence_rule
 * @property Carbon|null $next_recurrence_at
 * @property Carbon|null $remind_at
 * @property Carbon|null $reminder_sent_at
 * @property string|null $automation_key
 * @property string|null $owner_id
 * @property-read Project $project
 * @property-read Milestone|null $milestone
 * @property-read Sprint|null $sprint
 * @property-read Task|null $parent
 * @property-read Collection<int, Task> $subtasks
 * @property-read Collection<int, TaskAssignee> $assignees
 * @property-read Collection<int, ChecklistItem> $checklistItems
 * @property-read Collection<int, TaskComment> $comments
 * @property-read Collection<int, TaskDependency> $dependencies
 * @property-read Collection<int, TaskWatcher> $watchers
 * @property-read Collection<int, TimeEntry> $timeEntries
 */
class Task extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids, SoftDeletes;

    protected $table = 'project_tasks';

    protected $fillable = ['project_id', 'parent_id', 'milestone_id', 'sprint_id', 'title', 'description', 'status', 'priority', 'starts_at', 'due_at', 'completed_at', 'estimated_minutes', 'progress', 'position', 'client_visible', 'recurrence_rule', 'next_recurrence_at', 'remind_at', 'reminder_sent_at', 'automation_key'];

    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'priority' => Priority::class, 'starts_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime', 'estimated_minutes' => 'integer', 'progress' => 'integer', 'position' => 'integer', 'client_visible' => 'boolean', 'recurrence_rule' => 'array', 'next_recurrence_at' => 'datetime', 'remind_at' => 'datetime', 'reminder_sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return BelongsTo<Sprint, $this> */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Task, $this> */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<TaskAssignee, $this> */
    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class);
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /** @return HasMany<TaskDependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class);
    }

    /** @return HasMany<TaskWatcher, $this> */
    public function watchers(): HasMany
    {
        return $this->hasMany(TaskWatcher::class);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function auditLabel(): string
    {
        return $this->title;
    }
}
