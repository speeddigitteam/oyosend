<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Projects\Enums\SprintStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $name
 * @property string|null $goal
 * @property SprintStatus $status
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $completed_at
 * @property-read Project $project
 */
class Sprint extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_sprints';

    protected $fillable = ['project_id', 'name', 'goal', 'status', 'starts_on', 'ends_on', 'completed_at'];

    protected function casts(): array
    {
        return ['status' => SprintStatus::class, 'starts_on' => 'date', 'ends_on' => 'date', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
