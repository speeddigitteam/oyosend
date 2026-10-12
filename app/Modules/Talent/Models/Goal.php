<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\GoalKind;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Models\Concerns\Placed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $employee_id
 * @property string|null $cycle_id
 * @property string $title
 * @property string|null $description
 * @property GoalKind $kind
 * @property string|null $unit
 * @property int $start_value
 * @property int $target_value
 * @property int $current_value
 * @property GoalStatus $status
 * @property Carbon|null $due_on
 * @property Carbon|null $achieved_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Goal extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;
    use Placed;

    protected $table = 'talent_goals';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'employee_id', 'cycle_id', 'title', 'description', 'kind', 'unit', 'start_value', 'target_value', 'current_value', 'status', 'due_on', 'achieved_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => GoalKind::class,
            'start_value' => 'integer',
            'target_value' => 'integer',
            'current_value' => 'integer',
            'status' => GoalStatus::class,
            'due_on' => 'date',
            'achieved_at' => 'datetime',
        ];
    }

    /** @return HasMany<GoalUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(GoalUpdate::class, 'goal_id')->orderByDesc('created_at');
    }

    public function auditLabel(): string
    {
        return (string) ($this->title);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.goal.'.$verb;
    }
}
