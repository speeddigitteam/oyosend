<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\AssignmentMode;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's inbox. Tickets are routed to a queue; the queue's agents work them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $department_id
 * @property string|null $sla_policy_id
 * @property string $name
 * @property string|null $description
 * @property AssignmentMode $assignment_mode
 * @property string|null $last_assigned_agent_id
 * @property bool $is_active
 */
class Queue extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_queues';

    /** @var list<string> */
    protected $fillable = ['department_id', 'sla_policy_id', 'name', 'description', 'assignment_mode', 'last_assigned_agent_id', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['assignment_mode' => AssignmentMode::class, 'is_active' => 'boolean'];
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['last_assigned_agent_id'];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }
}
