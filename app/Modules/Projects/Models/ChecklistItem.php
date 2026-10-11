<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $task_id
 * @property string $title
 * @property bool $is_completed
 * @property int $position
 * @property string|null $completed_by
 * @property Carbon|null $completed_at
 * @property-read Task $task
 * @property-read TenantMembership|null $completedBy
 */
class ChecklistItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_task_checklist_items';

    protected $fillable = ['task_id', 'title', 'is_completed', 'position', 'completed_by', 'completed_at'];

    protected function casts(): array
    {
        return ['is_completed' => 'boolean', 'position' => 'integer', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'completed_by');
    }
}
