<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $task_id
 * @property string $depends_on_task_id
 * @property string $type
 * @property-read Task $task
 * @property-read Task $dependsOn
 */
class TaskDependency extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_task_dependencies';

    protected $fillable = ['task_id', 'depends_on_task_id', 'type'];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
    }
}
