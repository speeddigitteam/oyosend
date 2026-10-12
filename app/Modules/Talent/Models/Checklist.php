<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Enums\ChecklistStatus;
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
 * @property ChecklistKind $kind
 * @property string|null $template_id
 * @property string $name
 * @property Carbon $anchor_date
 * @property ChecklistStatus $status
 * @property string $source_key
 * @property string|null $notes
 * @property Carbon|null $completed_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Checklist extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;
    use Placed;

    protected $table = 'talent_checklists';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'employee_id', 'kind', 'template_id', 'name', 'anchor_date', 'status', 'source_key', 'notes', 'completed_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ChecklistKind::class,
            'anchor_date' => 'date',
            'status' => ChecklistStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<ChecklistTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ChecklistTask::class, 'checklist_id')->orderBy('position');
    }

    public function auditLabel(): string
    {
        return (string) ($this->name);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.checklist.'.$verb;
    }
}
