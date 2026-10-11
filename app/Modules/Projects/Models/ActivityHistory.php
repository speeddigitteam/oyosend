<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string|null $task_id
 * @property string $event
 * @property string $summary
 * @property string|null $actor_id
 * @property array<string, scalar|null>|null $metadata
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read Task|null $task
 * @property-read TenantMembership|null $actor
 */
class ActivityHistory extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $table = 'project_activity_history';

    protected $fillable = ['project_id', 'task_id', 'event', 'summary', 'actor_id', 'metadata', 'occurred_at', 'created_at'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Project activity history is immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Project activity history is immutable.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_id');
    }
}
