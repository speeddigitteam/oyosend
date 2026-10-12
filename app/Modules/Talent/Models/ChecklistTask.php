<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\AssigneeRole;
use App\Modules\Talent\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $checklist_id
 * @property string $title
 * @property string|null $description
 * @property int $position
 * @property AssigneeRole $assignee_role
 * @property string|null $assignee_employee_id
 * @property Carbon|null $due_on
 * @property TaskStatus $status
 * @property Carbon|null $completed_at
 * @property string|null $completed_by
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChecklistTask extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_checklist_tasks';

    /** @var list<string> */
    protected $fillable = ['checklist_id', 'title', 'description', 'position', 'assignee_role', 'assignee_employee_id', 'due_on', 'status', 'completed_at', 'completed_by', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'assignee_role' => AssigneeRole::class,
            'due_on' => 'date',
            'status' => TaskStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Checklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class, 'checklist_id');
    }
}
