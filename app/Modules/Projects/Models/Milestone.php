<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Projects\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $name
 * @property string|null $description
 * @property Carbon $due_on
 * @property MilestoneStatus $status
 * @property Carbon|null $completed_at
 * @property-read Project $project
 * @property-read Collection<int, Task> $tasks
 */
class Milestone extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_milestones';

    protected $fillable = ['project_id', 'name', 'description', 'due_on', 'status', 'completed_at'];

    protected function casts(): array
    {
        return ['status' => MilestoneStatus::class, 'due_on' => 'date', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
