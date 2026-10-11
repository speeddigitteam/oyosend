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
 * @property string $membership_id
 * @property Carbon $created_at
 * @property-read Task $task
 * @property-read TenantMembership $membership
 */
class TaskWatcher extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $table = 'project_task_watchers';

    protected $fillable = ['task_id', 'membership_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class);
    }
}
